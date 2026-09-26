<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Fabrique les fichiers audio que les Sonos viennent chercher : synthèse
 * vocale (Piper en local, OpenAI en ligne) et messages enregistrés au micro.
 *
 * Tout aboutit dans data/audio/, servi par Apache sous un nom impossible à
 * deviner : l'enceinte lit une URL en HTTP, sans session Jeedom.
 *
 *   data/audio/cache/  synthèses, réutilisées tant que le texte et la voix
 *                      ne changent pas, purgées au-delà de CACHE_BYTES ;
 *   data/audio/rec/    enregistrements du micro et fichiers joués par URL,
 *                      effacés après REC_SECONDS.
 *
 * Piper n'est pas une dépendance du plugin : le programme (environ 50 Mo) et
 * les voix (environ 65 Mo chacune) se téléchargent depuis la page de
 * configuration, dans data/piper/, sans apt ni pip.
 */
class sonosbeVoice {

    /* Version de Piper publiée en binaires autonomes (onnxruntime et
     * espeak-ng inclus). */
    const PIPER_RELEASE = '2023.11.14-2';

    /* Clé => fichier du modèle, locuteur éventuel, libellé. */
    const VOICES = array(
        'siwis'        => array('model' => 'fr_FR-siwis-medium', 'path' => 'fr/fr_FR/siwis/medium', 'speaker' => null, 'name' => 'Siwis — femme'),
        'tom'          => array('model' => 'fr_FR-tom-medium', 'path' => 'fr/fr_FR/tom/medium', 'speaker' => null, 'name' => 'Tom — homme'),
        'upmc:jessica' => array('model' => 'fr_FR-upmc-medium', 'path' => 'fr/fr_FR/upmc/medium', 'speaker' => 0, 'name' => 'Jessica — femme'),
        'upmc:pierre'  => array('model' => 'fr_FR-upmc-medium', 'path' => 'fr/fr_FR/upmc/medium', 'speaker' => 1, 'name' => 'Pierre — homme'),
        'gilles'       => array('model' => 'fr_FR-gilles-low', 'path' => 'fr/fr_FR/gilles/low', 'speaker' => null, 'name' => 'Gilles — homme, qualité réduite'),
    );
    const DEFAULT_VOICE = 'siwis';

    const OPENAI_URL = 'https://api.openai.com/v1/audio/speech';
    const OPENAI_MODEL = 'gpt-4o-mini-tts';
    const OPENAI_VOICE = 'coral';
    const OPENAI_VOICES = array('alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar');

    /* Au-delà, les synthèses les plus anciennes sont effacées. */
    const CACHE_BYTES = 150 * 1024 * 1024;
    const REC_SECONDS = 86400;

    /* Silence ajouté avant et après : une enceinte qui sort de veille, ou qui
     * baisse la musique, mange sinon le début du premier mot. */
    const LEAD_MS = 350;
    const TAIL_MS = 250;

    const MAX_TEXT = 4000;
    const MAX_RECORDING_BYTES = 8 * 1024 * 1024;

    /* ============================================================= DOSSIERS */

    public static function dataDir() {
        return realpath(__DIR__ . '/../../data') ?: __DIR__ . '/../../data';
    }

    public static function audioDir($_kind) {
        $dir = self::dataDir() . '/audio/' . $_kind;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /* URL sous laquelle l'enceinte trouve le fichier. Jeedom tel que le voit
     * le réseau local, en HTTP : une enceinte refuse un certificat
     * auto-signé. */
    public static function baseUrl() {
        $base = trim((string) config::byKey('sonos_url', 'sonosbe', ''));
        if ($base === '') {
            $base = network::getNetworkAccess('internal');
        }
        return rtrim($base, '/');
    }

    public static function url($_file) {
        return self::baseUrl() . '/' . self::relativeUrl($_file);
    }

    /* Chemin servi par core/php/sonosbeAudio.php, et non par Apache
     * directement : le .htaccess de Jeedom refuse les WAV et les M4A. */
    public static function relativeUrl($_file) {
        $real = realpath($_file);
        $root = realpath(self::dataDir() . '/audio');
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new Exception(__('Fichier audio introuvable :', __FILE__) . ' ' . $_file);
        }
        return 'plugins/sonosbe/core/php/sonosbeAudio.php?f=' . str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
    }

    /* Type MIME d'après l'extension, pour les métadonnées UPnP. */
    public static function mimeOf($_name) {
        $types = array('mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
                       'flac' => 'audio/flac', 'ogg' => 'audio/ogg');
        $ext = strtolower(pathinfo((string) parse_url((string) $_name, PHP_URL_PATH), PATHINFO_EXTENSION));
        return isset($types[$ext]) ? $types[$ext] : 'audio/mpeg';
    }

    /* ============================================================= SYNTHÈSE */

    /*
     * Texte => fichier prêt à jouer : array(file, duration, engine, voice,
     * tone, cached). Le moteur choisi d'abord ; l'autre s'il échoue et
     * qu'il est prêt.
     *
     * $_overrides vient du titre de l'annonce (titleOptions) : « voice » (une
     * voix Piper ou OpenAI, qui décide alors du moteur) et « tone » (une
     * consigne de ton, comprise par OpenAI seulement).
     */
    public static function speak($_text, $_overrides = array()) {
        $text = self::cleanText($_text);
        if ($text === '') {
            throw new Exception(__('Aucun texte à dire.', __FILE__));
        }
        $overrides = $_overrides + array('voice' => null, 'tone' => null);
        $wanted = $overrides['voice'] !== null ? self::resolveVoice($overrides['voice']) : null;
        if ($overrides['voice'] !== null && $wanted === null) {
            log::add('sonosbe', 'warning', sprintf(__('Voix inconnue « %s » : voix par défaut.', __FILE__), $overrides['voice']));
        }
        $first = $wanted !== null ? $wanted['engine'] : (string) config::byKey('tts_engine', 'sonosbe', 'piper');
        $engines = array($first);
        if (config::byKey('tts_fallback', 'sonosbe', 1) == 1) {
            $engines[] = $first === 'openai' ? 'piper' : 'openai';
        }
        $errors = array();
        foreach ($engines as $engine) {
            /* La voix demandée ne vaut que pour son moteur ; le moteur de
             * secours parle avec sa voix habituelle. */
            $params = self::engineParams($engine, ($wanted !== null && $wanted['engine'] === $engine) ? $wanted['voice'] : null, $overrides['tone']);
            $problem = self::engineProblem($engine, $params['voice']);
            if ($problem !== '') {
                $errors[] = self::engineName($engine) . ' : ' . $problem;
                continue;
            }
            $key = sha1(self::secret() . '|' . $engine . '|' . implode('|', $params) . '|' . $text);
            $about = array('engine' => $engine, 'voice' => $params['voice'], 'tone' => $engine === 'openai' ? (string) $overrides['tone'] : '');
            $cached = self::cached($key);
            if ($cached !== null) {
                touch($cached['file']);
                return $cached + $about + array('cached' => true);
            }
            try {
                $started = microtime(true);
                $wav = $engine === 'openai'
                    ? self::openaiWav($text, $params['voice'], $params['instructions'])
                    : self::piperWav($text, $params['voice']);
                $clip = self::store($wav, self::audioDir('cache') . '/' . $key);
                if ($engine === 'openai') {
                    self::recordOpenaiUsage($text, $clip['duration']);
                }
                log::add('sonosbe', 'debug', sprintf('%s (%s) : %.1f s de voix en %.2f s', self::engineName($engine), $params['voice'],
                    $clip['duration'], microtime(true) - $started));
                return $clip + $about + array('cached' => false);
            } catch (Throwable $e) {
                $errors[] = self::engineName($engine) . ' : ' . $e->getMessage();
                log::add('sonosbe', 'warning', __('Synthèse vocale en échec', __FILE__) . ' — ' . end($errors));
            }
        }
        throw new Exception(__('Synthèse vocale impossible.', __FILE__) . ' ' . implode(' ; ', $errors));
    }

    /*
     * Ce qui change le son produit, moteur par moteur ; fait aussi partie de
     * la clé du cache. Voix : celle demandée, sinon celle de la
     * configuration.
     */
    private static function engineParams($_engine, $_voice, $_tone) {
        if ($_engine === 'openai') {
            return array(
                'model'        => self::openaiModel(),
                'voice'        => $_voice !== null ? $_voice : self::openaiVoice(),
                'instructions' => self::openaiInstructions($_tone),
            );
        }
        return array('voice' => $_voice !== null ? $_voice : self::piperVoice(), 'speed' => (string) self::piperSpeed());
    }

    /* Une voix nommée dans un titre : Piper (par sa clé ou son prénom) ou
     * OpenAI. Null si inconnue. */
    public static function resolveVoice($_name) {
        $name = strtolower(trim((string) $_name));
        $aliases = array('jessica' => 'upmc:jessica', 'pierre' => 'upmc:pierre');
        if (isset($aliases[$name])) {
            $name = $aliases[$name];
        }
        if (isset(self::VOICES[$name])) {
            return array('engine' => 'piper', 'voice' => $name);
        }
        if (in_array($name, self::OPENAI_VOICES, true)) {
            return array('engine' => 'openai', 'voice' => $name);
        }
        return null;
    }

    /* Une ligne, sans balisage ni caractères de contrôle : Piper lit une
     * phrase par ligne, et un tag « #…# » de scénario non remplacé ne doit
     * pas être prononcé. */
    public static function cleanText($_text) {
        $text = html_entity_decode(strip_tags((string) $_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, self::MAX_TEXT, 'UTF-8');
        }
        return $text;
    }

    public static function engineName($_engine) {
        return $_engine === 'openai' ? 'OpenAI' : 'Piper';
    }

    public static function engineReady($_engine) {
        return self::engineProblem($_engine) === '';
    }

    /* Ce qui empêche ce moteur de parler, avec cette voix Piper s'il y en a
     * une de demandée ; '' s'il est prêt. */
    public static function engineProblem($_engine, $_voice = null) {
        if ($_engine === 'openai') {
            return trim((string) config::byKey('openai_key', 'sonosbe', '')) === '' ? __('aucune clé API', __FILE__) : '';
        }
        if (!is_executable(self::piperBinary())) {
            return __('Piper n\'est pas téléchargé', __FILE__);
        }
        $voice = ($_voice !== null && isset(self::VOICES[$_voice])) ? $_voice : self::piperVoice();
        if (!is_file(self::voiceModel($voice))) {
            return sprintf(__('la voix %s n\'est pas téléchargée', __FILE__), self::VOICES[$voice]['name']);
        }
        return '';
    }

    /* Sel des noms de fichiers : un texte connu ne donne pas l'adresse de
     * son fichier. */
    private static function secret() {
        $secret = (string) config::byKey('audio_secret', 'sonosbe', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            config::save('audio_secret', $secret, 'sonosbe');
        }
        return $secret;
    }

    private static function cached($_key) {
        foreach (array('mp3', 'wav') as $ext) {
            $file = self::audioDir('cache') . '/' . $_key . '.' . $ext;
            if (is_file($file) && filesize($file) > 0) {
                return array('file' => $file, 'duration' => self::duration($file));
            }
        }
        return null;
    }

    /* ================================================================ PIPER */

    public static function piperDir() {
        return self::dataDir() . '/piper';
    }

    public static function piperBinary() {
        return self::piperDir() . '/piper/piper';
    }

    public static function voiceModel($_voice) {
        $voice = isset(self::VOICES[$_voice]) ? self::VOICES[$_voice] : self::VOICES[self::DEFAULT_VOICE];
        return self::piperDir() . '/voices/' . $voice['model'] . '.onnx';
    }

    public static function piperVoice() {
        $voice = (string) config::byKey('piper_voice', 'sonosbe', self::DEFAULT_VOICE);
        return isset(self::VOICES[$voice]) ? $voice : self::DEFAULT_VOICE;
    }

    /* En pour cent : 100 = débit naturel, 120 = plus rapide. */
    public static function piperSpeed() {
        $speed = (int) config::byKey('piper_speed', 'sonosbe', 100);
        return max(50, min(200, $speed > 0 ? $speed : 100));
    }

    public static function piperWav($_text, $_voice = null) {
        $key = ($_voice !== null && isset(self::VOICES[$_voice])) ? $_voice : self::piperVoice();
        $voice = self::VOICES[$key];
        $out = tempnam(jeedom::getTmpFolder('sonosbe'), 'piper');
        $command = array(self::piperBinary(), '--model', self::voiceModel($key), '--output_file', $out,
                         '--length_scale', sprintf('%.2F', 100 / self::piperSpeed()));
        if ($voice['speaker'] !== null) {
            $command[] = '--speaker';
            $command[] = (string) $voice['speaker'];
        }
        try {
            $result = self::run($command, $_text . "\n", 60);
            $wav = (string) @file_get_contents($out);
        } finally {
            @unlink($out);
        }
        if ($result['code'] !== 0 || strlen($wav) < 100) {
            throw new Exception(sprintf(__('Piper a échoué (code %d) : %s', __FILE__), $result['code'], self::lastLine($result['stderr'])));
        }
        return $wav;
    }

    /* =============================================================== OPENAI */

    public static function openaiModel() {
        $model = trim((string) config::byKey('openai_model', 'sonosbe', ''));
        return $model !== '' ? $model : self::OPENAI_MODEL;
    }

    public static function openaiVoice() {
        $voice = trim((string) config::byKey('openai_voice', 'sonosbe', ''));
        return $voice !== '' ? $voice : self::OPENAI_VOICE;
    }

    /* Tons prêts à l'emploi pour « ton=… » ; un autre mot est passé tel
     * quel (« ton=malicieux » : « Parle d'un ton malicieux. »). Clés sans
     * accents : « ton=enjoue » vaut « ton=enjoué ». */
    const TONES = array(
        'enjoue'    => 'Parle d\'un ton enjoué et chaleureux, avec le sourire.',
        'joyeux'    => 'Parle d\'un ton joyeux et enthousiaste.',
        'serieux'   => 'Parle d\'un ton sérieux et posé, sans émotion superflue.',
        'calme'     => 'Parle calmement, d\'une voix douce et posée.',
        'doux'      => 'Parle doucement, d\'une voix douce et apaisante.',
        'chuchote'  => 'Chuchote, d\'une voix très douce, comme pour ne réveiller personne.',
        'alerte'    => 'Parle d\'un ton ferme et pressant, comme une alerte, sans crier.',
        'solennel'  => 'Parle d\'un ton solennel.',
        'dynamique' => 'Parle d\'un ton dynamique et entraînant.',
    );

    public static function toneInstruction($_tone) {
        $tone = trim((string) $_tone);
        if ($tone === '') {
            return '';
        }
        $key = strtolower(self::stripAccents($tone));
        return isset(self::TONES[$key]) ? self::TONES[$key] : 'Parle d\'un ton ' . $tone . '.';
    }

    /* Consigne envoyée à OpenAI : celle de la configuration, puis le ton de
     * l'annonce. */
    public static function openaiInstructions($_tone = null) {
        return trim(trim((string) config::byKey('openai_instructions', 'sonosbe', '')) . ' ' . self::toneInstruction($_tone));
    }

    public static function stripAccents($_text) {
        $from = array('à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç', 'À', 'Â', 'É', 'È', 'Ê', 'Î', 'Ô', 'Ù', 'Û', 'Ç');
        $to = array('a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c', 'A', 'A', 'E', 'E', 'E', 'I', 'O', 'U', 'U', 'C');
        return str_replace($from, $to, (string) $_text);
    }

    public static function openaiWav($_text, $_voice = null, $_instructions = null) {
        $body = array('model' => self::openaiModel(), 'voice' => $_voice !== null ? $_voice : self::openaiVoice(),
                      'input' => $_text, 'response_format' => 'wav');
        $instructions = $_instructions !== null ? trim((string) $_instructions) : self::openaiInstructions();
        /* Seuls les modèles « gpt-…-tts » acceptent des consignes de ton. */
        if ($instructions !== '' && strpos(self::openaiModel(), 'gpt-') === 0) {
            $body['instructions'] = $instructions;
        }
        $ch = curl_init(self::OPENAI_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json',
                                            'Authorization: Bearer ' . trim((string) config::byKey('openai_key', 'sonosbe', ''))),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 45,
        ));
        $answer = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($answer === false || $error !== '') {
            throw new Exception(__('OpenAI injoignable :', __FILE__) . ' ' . $error);
        }
        if ($code !== 200) {
            $json = json_decode((string) $answer, true);
            $message = isset($json['error']['message']) ? $json['error']['message'] : substr((string) $answer, 0, 200);
            throw new Exception('HTTP ' . $code . ' — ' . $message);
        }
        return (string) $answer;
    }

    /* =========================================================== MICROPHONE */

    /*
     * Un message enregistré dans le navigateur : WebM/Opus (Chrome, Firefox)
     * ou MP4/AAC (Safari). Les Sonos ne lisent pas le WebM : ffmpeg le
     * convertit, en montant le volume, souvent faible au micro d'un
     * téléphone. Sans ffmpeg, seul le MP4 de Safari passe tel quel.
     */
    public static function recording($_tmpFile, $_mime) {
        $size = @filesize($_tmpFile);
        if (!$size) {
            throw new Exception(__('Enregistrement vide.', __FILE__));
        }
        if ($size > self::MAX_RECORDING_BYTES) {
            throw new Exception(__('Enregistrement trop long.', __FILE__));
        }
        $base = self::audioDir('rec') . '/' . bin2hex(random_bytes(16));
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg !== '') {
            $filter = 'adelay=' . self::LEAD_MS . ':all=1,loudnorm=I=-16:TP=-1.5,apad=pad_dur=' . (self::TAIL_MS / 1000);
            $result = self::run(array($ffmpeg, '-y', '-v', 'error', '-i', $_tmpFile, '-af', $filter,
                                      '-ac', '2', '-ar', '44100', '-b:a', '128k', $base . '.mp3'), '', 60);
            if ($result['code'] !== 0 || !is_file($base . '.mp3')) {
                @unlink($base . '.mp3');
                throw new Exception(__('Conversion de l\'enregistrement impossible :', __FILE__) . ' ' . self::lastLine($result['stderr']));
            }
            return array('file' => $base . '.mp3', 'duration' => self::duration($base . '.mp3'));
        }
        $mime = strtolower((string) $_mime);
        if (strpos($mime, 'audio/mp4') === 0 || strpos($mime, 'audio/aac') === 0 || strpos($mime, 'audio/mpeg') === 0) {
            $ext = strpos($mime, 'audio/mpeg') === 0 ? '.mp3' : '.m4a';
            if (!@copy($_tmpFile, $base . $ext)) {
                throw new Exception(__('Impossible d\'enregistrer le message.', __FILE__));
            }
            return array('file' => $base . $ext, 'duration' => 0);
        }
        throw new Exception(__('Ce navigateur enregistre dans un format que les Sonos ne lisent pas, et ffmpeg, qui le convertirait, est absent de Jeedom.', __FILE__));
    }

    /* =========================================================== FICHIERS */

    /* WAV => fichier final : silence autour, puis MP3 si ffmpeg est là. Le
     * WAV reste lisible par les Sonos, mais pèse dix fois plus. */
    public static function store($_wav, $_base) {
        $wav = self::padWav($_wav, self::LEAD_MS, self::TAIL_MS);
        $duration = self::wavDuration($wav);
        $ffmpeg = self::ffmpeg();
        /* Noms provisoires propres à ce processus : deux scénarios qui disent
         * la même phrase au même moment ne s'écrasent pas l'un l'autre, et le
         * fichier final n'apparaît qu'entier (rename est atomique). */
        $unique = '.' . getmypid() . '-' . bin2hex(random_bytes(4));
        if ($ffmpeg !== '') {
            $tmp = $_base . $unique . '.wav';
            file_put_contents($tmp, $wav);
            $result = self::run(array($ffmpeg, '-y', '-v', 'error', '-i', $tmp, '-ac', '2', '-ar', '44100', '-b:a', '128k',
                                      '-f', 'mp3', $_base . $unique . '.part'), '', 30);
            @unlink($tmp);
            if ($result['code'] === 0) {
                self::remember($_base . '.mp3', $duration);
                if (@rename($_base . $unique . '.part', $_base . '.mp3')) {
                    return array('file' => $_base . '.mp3', 'duration' => $duration);
                }
            }
            @unlink($_base . $unique . '.part');
            log::add('sonosbe', 'debug', 'ffmpeg : ' . self::lastLine($result['stderr']));
        }
        file_put_contents($_base . $unique . '.part', $wav);
        rename($_base . $unique . '.part', $_base . '.wav');
        return array('file' => $_base . '.wav', 'duration' => $duration);
    }

    /* Durée d'un MP3 : notée à côté du fichier par store(). Sinon, lue par
     * ffmpeg ; 0 si rien ne permet de la connaître. */
    public static function duration($_file) {
        if (substr($_file, -4) === '.wav') {
            return self::wavDuration((string) @file_get_contents($_file, false, null, 0, 4096), (int) @filesize($_file));
        }
        $meta = @json_decode((string) @file_get_contents($_file . '.json'), true);
        if (is_array($meta) && isset($meta['duration'])) {
            return (float) $meta['duration'];
        }
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg !== '') {
            $result = self::run(array($ffmpeg, '-hide_banner', '-i', $_file), '', 10);
            if (preg_match('/Duration: (\d+):(\d+):(\d+(?:\.\d+)?)/', $result['stderr'], $m)) {
                $duration = $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
                self::remember($_file, $duration);
                return $duration;
            }
        }
        return 0.0;
    }

    private static function remember($_file, $_duration) {
        @file_put_contents($_file . '.json', json_encode(array('duration' => round($_duration, 2))));
    }

    /*
     * Structure d'un WAV PCM : où sont le format et les données. Piper et
     * OpenAI écrivent parfois une taille de données fausse (0 ou 0xFFFFFFFF,
     * quand ils écrivent en flux) : on prend alors la fin du fichier.
     */
    public static function wavInfo($_wav, $_fileSize = null) {
        if (strlen($_wav) < 12 || substr($_wav, 0, 4) !== 'RIFF' || substr($_wav, 8, 4) !== 'WAVE') {
            throw new Exception(__('Fichier WAV illisible.', __FILE__));
        }
        $total = $_fileSize !== null ? $_fileSize : strlen($_wav);
        $pos = 12;
        $fmt = null;
        while ($pos + 8 <= strlen($_wav)) {
            $id = substr($_wav, $pos, 4);
            $size = unpack('V', substr($_wav, $pos + 4, 4))[1];
            if ($id === 'fmt ') {
                $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($_wav, $pos + 8, 16));
            } elseif ($id === 'data') {
                if ($fmt === null) {
                    break;
                }
                $available = $total - ($pos + 8);
                if ($size === 0 || $size > $available) {
                    $size = $available;
                }
                return array('fmt' => $fmt, 'data_offset' => $pos + 8, 'data_size' => $size);
            }
            $pos += 8 + $size + ($size % 2);
        }
        throw new Exception(__('Fichier WAV sans données audio.', __FILE__));
    }

    public static function wavDuration($_wav, $_fileSize = null) {
        try {
            $info = self::wavInfo($_wav, $_fileSize);
        } catch (Exception $e) {
            return 0.0;
        }
        return $info['fmt']['byterate'] > 0 ? round($info['data_size'] / $info['fmt']['byterate'], 2) : 0.0;
    }

    /* Réécrit un WAV propre (en-tête de 44 octets) avec du silence avant et
     * après les données. */
    public static function padWav($_wav, $_leadMs, $_tailMs) {
        $info = self::wavInfo($_wav);
        $fmt = $info['fmt'];
        $block = max(1, (int) $fmt['block']);
        $bytes = function ($_ms) use ($fmt, $block) {
            return intdiv((int) round($fmt['byterate'] * $_ms / 1000), $block) * $block;
        };
        /* Silence : zéro en PCM signé ; 0x80 en 8 bits, non signé. */
        $zero = $fmt['bits'] == 8 ? "\x80" : "\x00";
        $data = str_repeat($zero, $bytes($_leadMs))
              . substr($_wav, $info['data_offset'], $info['data_size'])
              . str_repeat($zero, $bytes($_tailMs));
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
             . 'fmt ' . pack('VvvVVvv', 16, $fmt['format'], $fmt['channels'], $fmt['rate'], $fmt['byterate'], $fmt['block'], $fmt['bits'])
             . 'data' . pack('V', strlen($data)) . $data;
    }

    /* ======================================================= USAGE OPENAI */

    /*
     * Ce qu'OpenAI a réellement fabriqué ce mois-ci : caractères envoyés,
     * requêtes, secondes de voix, coût estimé. Une phrase resservie par le
     * cache n'y figure pas : elle n'est pas repartie chez OpenAI.
     *
     * Le coût est une estimation, en dollars, d'après les tarifs publics
     * d'OpenAI : 15 $ le million de caractères pour tts-1, 30 $ pour
     * tts-1-hd, et environ 0,015 $ la minute de voix pour gpt-4o-mini-tts.
     * La facture d'OpenAI fait foi.
     */
    const OPENAI_PRICES = array(
        'tts-1'    => array('per_million_chars' => 15.0),
        'tts-1-hd' => array('per_million_chars' => 30.0),
        'default'  => array('per_minute' => 0.015),
    );

    public static function estimateCost($_model, $_chars, $_seconds) {
        $price = isset(self::OPENAI_PRICES[$_model]) ? self::OPENAI_PRICES[$_model] : self::OPENAI_PRICES['default'];
        return isset($price['per_million_chars'])
            ? $_chars * $price['per_million_chars'] / 1000000
            : $_seconds / 60 * $price['per_minute'];
    }

    /* Le mois en cours, et le précédent. Un changement de mois bascule le
     * compteur, même sans nouvelle annonce (appelée chaque heure). */
    public static function openaiUsage($_month = null) {
        $month = $_month !== null ? $_month : date('Y-m');
        /* config::byKey décode lui-même une valeur JSON : on reçoit un
         * tableau, pas un texte. */
        $usage = config::byKey('openai_usage', 'sonosbe', '');
        if (is_string($usage)) {
            $usage = json_decode($usage, true);
        }
        $empty = array('month' => $month, 'chars' => 0, 'requests' => 0, 'seconds' => 0.0, 'cost' => 0.0);
        if (!is_array($usage) || !isset($usage['month'])) {
            return array('current' => $empty, 'previous' => null);
        }
        if ($usage['month'] !== $month) {
            $previous = $usage;
            unset($previous['previous']);
            return array('current' => $empty, 'previous' => $previous);
        }
        $previous = isset($usage['previous']) ? $usage['previous'] : null;
        unset($usage['previous']);
        return array('current' => $usage + $empty, 'previous' => $previous);
    }

    public static function recordOpenaiUsage($_text, $_seconds) {
        $usage = self::openaiUsage();
        $chars = function_exists('mb_strlen') ? mb_strlen($_text, 'UTF-8') : strlen($_text);
        $current = $usage['current'];
        $current['chars'] += $chars;
        $current['requests'] += 1;
        $current['seconds'] = round($current['seconds'] + $_seconds, 1);
        $current['cost'] = round($current['cost'] + self::estimateCost(self::openaiModel(), $chars, $_seconds), 4);
        self::saveOpenaiUsage($current, $usage['previous']);
        return $current;
    }

    public static function saveOpenaiUsage($_current, $_previous) {
        config::save('openai_usage', $_current + array('previous' => $_previous), 'sonosbe');
        /* Les infos de l'équipement « Toutes les enceintes », s'il existe. */
        if (class_exists('sonosbe', false)) {
            sonosbe::publishOpenaiUsage($_current);
        }
    }

    /* ============================================================= OPTIONS */

    /*
     * Le titre d'une commande d'annonce : un volume et des mots-clés, dans
     * n'importe quel ordre — « 40 », « 40 % carillon », « urgent ». Rend
     * volume (null : celui de l'équipement), chime (null : réglage de
     * l'équipement) et urgent (passe outre la plage de nuit).
     */
    public static function titleOptions($_title) {
        $options = array('volume' => null, 'chime' => null, 'urgent' => false, 'voice' => null, 'tone' => null);
        $title = function_exists('mb_strtolower') ? mb_strtolower((string) $_title, 'UTF-8') : strtolower((string) $_title);
        foreach (preg_split('/[\s,;]+/u', trim($title)) as $word) {
            $number = rtrim($word, '%');
            if (preg_match('/^(voix|voice|ton|tone)[=:](.+)$/u', $word, $m)) {
                $options[in_array($m[1], array('voix', 'voice'), true) ? 'voice' : 'tone'] = $m[2];
            } elseif ($number !== '' && is_numeric($number)) {
                $options['volume'] = max(0, min(100, (int) round((float) $number)));
            } elseif (in_array($word, array('carillon', 'ding', 'chime'), true)) {
                $options['chime'] = true;
            } elseif (in_array($word, array('sans-carillon', 'sanscarillon', 'nochime', '-carillon'), true)) {
                $options['chime'] = false;
            } elseif (in_array($word, array('urgent', 'urgence'), true)) {
                $options['urgent'] = true;
            }
        }
        return $options;
    }

    /* Plage de nuit : « 22:00 » à « 07:00 » passe minuit. Des bornes égales
     * ou illisibles ne couvrent rien. */
    public static function inTimeRange($_now, $_start, $_end) {
        $minutes = function ($_hhmm) {
            return preg_match('/^(\d{1,2})[:hH](\d{2})$/', trim((string) $_hhmm), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
                ? (int) $m[1] * 60 + (int) $m[2] : null;
        };
        $now = $minutes($_now);
        $start = $minutes($_start);
        $end = $minutes($_end);
        if ($now === null || $start === null || $end === null || $start === $end) {
            return false;
        }
        return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    public static function nightActive() {
        return (int) config::byKey('night_enable', 'sonosbe', 0) === 1
            && self::inTimeRange(date('H:i'), config::byKey('night_start', 'sonosbe', '22:00'), config::byKey('night_end', 'sonosbe', '07:00'));
    }

    public static function nightVolume() {
        $volume = config::byKey('night_volume', 'sonosbe', 15);
        return is_numeric($volume) ? max(0, min(100, (int) $volume)) : 15;
    }

    /* ============================================================ CARILLON */

    const CHIME_FILE = 'chime-v1.wav';

    /*
     * Un « ding-dong » de sonnette, calculé ici : deux notes (mi puis do)
     * avec leurs harmoniques et une décroissance de cloche. Aucun fichier à
     * fournir, le même son sur une S1 et une S2, et une durée connue, qui
     * permet d'enchaîner le message juste après.
     */
    public static function chimeWav($_rate = 44100) {
        $notes = array(array('at' => 0.0, 'freq' => 659.25), array('at' => 0.5, 'freq' => 523.25));
        $partials = array(array(1.0, 1.0), array(2.0, 0.35), array(3.0, 0.12), array(4.2, 0.05));
        $length = 1.9;
        $count = (int) round($length * $_rate);
        $pcm = '';
        for ($i = 0; $i < $count; $i++) {
            $t = $i / $_rate;
            $sample = 0.0;
            foreach ($notes as $note) {
                $u = $t - $note['at'];
                if ($u < 0) {
                    continue;
                }
                $envelope = min(1.0, $u / 0.006) * exp(-3.2 * $u);
                foreach ($partials as $partial) {
                    $sample += $partial[1] * $envelope * sin(2 * M_PI * $note['freq'] * $partial[0] * $u);
                }
            }
            /* Fondu final : pas de clic à la coupure. */
            $fade = min(1.0, ($length - $t) / 0.05);
            $pcm .= pack('v', (int) round(max(-1.0, min(1.0, 0.34 * $sample * $fade)) * 32767) & 0xFFFF);
        }
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
             . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $_rate, $_rate * 2, 2, 16)
             . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    /* Le carillon, fabriqué au premier usage ; refait s'il a été purgé. */
    public static function chimeFile() {
        $file = self::audioDir('cache') . '/' . self::CHIME_FILE;
        if (!is_file($file)) {
            $tmp = $file . '.' . getmypid() . '.part';
            file_put_contents($tmp, self::chimeWav());
            rename($tmp, $file);
        }
        return $file;
    }

    /* ============================================================ ENTRETIEN */

    /* Appelée chaque heure. */
    public static function purge() {
        foreach (glob(self::audioDir('rec') . '/*') ?: array() as $file) {
            if (is_file($file) && time() - filemtime($file) > self::REC_SECONDS) {
                @unlink($file);
            }
        }
        /* Restes d'une synthèse interrompue (processus tué, disque plein). */
        foreach (glob(self::audioDir('cache') . '/*.part') ?: array() as $file) {
            if (time() - filemtime($file) > 3600) {
                @unlink($file);
            }
        }
        $files = array();
        $total = 0;
        foreach (glob(self::audioDir('cache') . '/*.{mp3,wav}', GLOB_BRACE) ?: array() as $file) {
            $files[$file] = filemtime($file);
            $total += filesize($file);
        }
        asort($files);
        foreach ($files as $file => $mtime) {
            if ($total <= self::CACHE_BYTES) {
                break;
            }
            $total -= filesize($file);
            @unlink($file);
            @unlink($file . '.json');
        }
    }

    public static function clearCache() {
        foreach (glob(self::audioDir('cache') . '/*') ?: array() as $file) {
            @unlink($file);
        }
    }

    /* ===================================================== INSTALLATION */

    public static function piperArch() {
        $machine = php_uname('m');
        $map = array('x86_64' => 'x86_64', 'amd64' => 'x86_64', 'aarch64' => 'aarch64', 'arm64' => 'aarch64',
                     'armv7l' => 'armv7l', 'armv8l' => 'armv7l');
        return isset($map[$machine]) ? $map[$machine] : '';
    }

    public static function installLog() {
        return log::getPathToLog('sonosbe_piper');
    }

    private static function installPidFile() {
        return jeedom::getTmpFolder('sonosbe') . '/piper-install.pid';
    }

    public static function installRunning() {
        $pid = (int) @file_get_contents(self::installPidFile());
        return $pid > 0 && @posix_getsid($pid) !== false;
    }

    /* Télécharge Piper s'il manque, puis la voix demandée, en tâche de fond :
     * plus de 100 Mo sur une connexion lente dépasseraient le délai d'une
     * requête. La page suit l'avancement par piperStatus(). */
    public static function installPiper($_voice) {
        if (self::installRunning()) {
            throw new Exception(__('Un téléchargement est déjà en cours.', __FILE__));
        }
        if (!isset(self::VOICES[$_voice])) {
            throw new Exception(__('Voix inconnue :', __FILE__) . ' ' . $_voice);
        }
        $arch = self::piperArch();
        if ($arch === '') {
            throw new Exception(sprintf(__('Piper n\'existe pas pour ce processeur (%s).', __FILE__), php_uname('m')));
        }
        $voice = self::VOICES[$_voice];
        $script = realpath(__DIR__ . '/../../resources/install-piper.sh');
        $cmd = 'bash ' . escapeshellarg($script)
             . ' ' . escapeshellarg(self::piperDir())
             . ' ' . escapeshellarg('https://github.com/rhasspy/piper/releases/download/' . self::PIPER_RELEASE . '/piper_linux_' . $arch . '.tar.gz')
             . ' ' . escapeshellarg($voice['model'])
             . ' ' . escapeshellarg('https://huggingface.co/rhasspy/piper-voices/resolve/main/' . $voice['path'] . '/' . $voice['model']);
        file_put_contents(self::installLog(), '');
        exec('nohup ' . $cmd . ' >> ' . escapeshellarg(self::installLog()) . ' 2>&1 & echo $!', $out);
        file_put_contents(self::installPidFile(), trim(implode('', $out)));
        log::add('sonosbe', 'info', sprintf(__('Téléchargement de Piper et de la voix %s', __FILE__), $voice['name']));
    }

    public static function piperStatus() {
        $voices = array();
        foreach (self::VOICES as $key => $voice) {
            $voices[$key] = array('name' => $voice['name'], 'installed' => is_file(self::voiceModel($key)));
        }
        $log = (string) @file_get_contents(self::installLog());
        $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
        return array(
            'arch'      => self::piperArch(),
            'machine'   => php_uname('m'),
            'binary'    => is_executable(self::piperBinary()),
            'voices'    => $voices,
            'running'   => self::installRunning(),
            'log'       => array_slice($lines, -12),
            'ffmpeg'    => self::ffmpeg() !== '',
            'engine'    => (string) config::byKey('tts_engine', 'sonosbe', 'piper'),
            'problem'   => self::engineProblem((string) config::byKey('tts_engine', 'sonosbe', 'piper')),
            'baseUrl'   => self::baseUrl(),
            'openai'    => self::openaiUsage(),
        );
    }

    /* ============================================================== OUTILS */

    public static function ffmpeg() {
        static $path = null;
        if ($path === null) {
            $path = '';
            foreach (array('ffmpeg', 'avconv') as $name) {
                $found = trim((string) @shell_exec('command -v ' . $name . ' 2>/dev/null'));
                if ($found !== '') {
                    $path = $found;
                    break;
                }
            }
        }
        return $path;
    }

    /* Lance un programme sans passer par le shell, avec un texte en entrée
     * et un délai maximal. */
    public static function run($_command, $_stdin, $_timeout) {
        $process = proc_open($_command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new Exception(__('Impossible de lancer', __FILE__) . ' ' . basename($_command[0]));
        }
        fwrite($pipes[0], $_stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $_timeout;
        $code = null;
        while (true) {
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 65536);
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $code = -1;
                $stderr .= "\n" . __('délai dépassé', __FILE__);
                break;
            }
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return array('code' => (int) $code, 'stdout' => $stdout, 'stderr' => $stderr);
    }

    private static function lastLine($_text) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $_text))));
        return empty($lines) ? '' : substr(end($lines), 0, 300);
    }
}
