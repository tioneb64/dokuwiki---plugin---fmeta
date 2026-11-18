<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\Utf8\Clean;

/**
 * Fmeta : lecture de l'en-tête YAML des pages, index des fiches, rendu de la carte d'en-tête
 * et des tableaux de pages. Les plugins fmtags et fmlife s'appuient sur ce helper.
 */
class helper_plugin_fmeta extends Plugin
{
    /** Clés canoniques et leurs variantes acceptées (Obsidian, autres outils) */
    public const ALIASES = [
        'title'   => ['title', 'titre'],
        'status'  => ['status', 'statut', 'etat', 'état'],
        'created' => ['created', 'creation', 'création', 'cree', 'créé'],
        'revue'   => ['last-revue', 'revue', 'last-review', 'review', 'updated', 'modified', 'modifie', 'modifié', 'last-updated'],
        'expire'  => ['expire', 'expires', 'expiration', 'expiry', 'valid-until', 'validite', 'validité'],
        'tags'    => ['tags', 'tag', 'hashtags', 'etiquettes', 'étiquettes'],
    ];

    /** @var array<string,array>|null index en mémoire */
    protected static $index = null;

    // region lecture de l'en-tête

    /**
     * Repère un en-tête YAML au tout début d'un texte
     *
     * Le bloc commence par une ligne --- (facultative, tolérée absente) et finit par une ligne ---.
     *
     * @param string $text
     * @return array{yaml:string,open:bool,first:int,close:int}|null
     */
    public function split(string $text): ?array
    {
        $lines = explode("\n", $text);
        $open = isset($lines[0]) && preg_match('/^---\s*$/', $lines[0]);
        $first = $open ? 1 : 0;
        if (!isset($lines[$first]) || !preg_match('/^[\p{L}\p{N}_-]+\s*:/u', $lines[$first])) return null;

        $close = null;
        $count = count($lines);
        for ($j = $first; $j < $count; $j++) {
            $l = rtrim($lines[$j], "\r");
            if (preg_match('/^---\s*$/', $l)) {
                $close = $j;
                break;
            }
            if ($l === '') {
                // une ligne vide n'est tolérée que si le bloc a été ouvert explicitement
                if (!$open) return null;
                continue;
            }
            if (!preg_match('/^(?:[\p{L}\p{N}_-]+\s*:.*|\s*-(?:\s.*)?|\s+.*|#.*)$/u', $l)) return null;
        }
        if ($close === null) return null;

        return [
            'yaml' => implode("\n", array_slice($lines, $first, $close - $first)),
            'open' => $open,
            'first' => $first,
            'close' => $close,
        ];
    }

    /**
     * Remplace les délimiteurs --- de l'en-tête par la balise interne <f> (même longueur, pour
     * ne pas décaler les positions de l'édition par section), que la syntaxe du plugin rend.
     *
     * @param string $text
     * @return string
     */
    public function mark(string $text): string
    {
        $b = $this->split($text);
        if ($b === null) return $text;

        $lines = explode("\n", $text);
        $lines[$b['close']] = preg_replace('/^---/', '<f>', $lines[$b['close']]);
        if ($b['open']) {
            $lines[0] = preg_replace('/^---/', '<f>', $lines[0]);
        } else {
            array_unshift($lines, '<f>');
        }
        return implode("\n", $lines);
    }

    /**
     * Lit un YAML simple : clés scalaires, listes "- a", listes [a, b]
     *
     * @param string $yaml
     * @return array<string,string|string[]>
     */
    public function parseYaml(string $yaml): array
    {
        $out = [];
        $key = null;
        foreach (preg_split('/\r?\n/', $yaml) as $line) {
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

            if (preg_match('/^\s*-\s*(.*)$/', $line, $m) && $key !== null && ($out[$key] === '' || is_array($out[$key]))) {
                if (!is_array($out[$key])) $out[$key] = [];
                $out[$key][] = $this->scalar($m[1]);
                continue;
            }
            if (preg_match('/^([^\s:][^:]*?)\s*:\s*(.*)$/u', $line, $m)) {
                $key = mb_strtolower(trim($m[1]));
                $v = trim($m[2]);
                if ($v === '') {
                    $out[$key] = '';
                } elseif ($v[0] === '[' && substr($v, -1) === ']') {
                    $items = array_map([$this, 'scalar'], explode(',', substr($v, 1, -1)));
                    $out[$key] = array_values(array_filter($items, static fn($i) => $i !== ''));
                } else {
                    $out[$key] = $this->scalar($v);
                }
            }
        }
        return $out;
    }

    /** Valeur scalaire sans guillemets */
    protected function scalar(string $v): string
    {
        $v = trim($v);
        // commentaire YAML en fin de ligne (« "hybrid"   # pub/sub ») ; une valeur qui commence par # (hashtag) est gardée
        if ($v !== '' && $v[0] !== '#') {
            if (preg_match('/^("[^"]*"|\'[^\']*\')\s+#.*$/u', $v, $m)) {
                $v = $m[1];
            } elseif ($v[0] !== '"' && $v[0] !== "'") {
                $v = rtrim(preg_replace('/\s+#.*$/u', '', $v));
            }
        }
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        return $v;
    }

    /** Date au format Y-m-d (accepte aussi JJ/MM/AAAA), chaîne vide si invalide */
    public function date($v): string
    {
        $v = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
        if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return '';
    }

    /**
     * Ramène les clés d'un en-tête aux clés canoniques
     *
     * @param array $raw résultat de parseYaml
     * @return array{title:string,status:string,created:string,revue:string,expire:string,tags:string[],fields:array}
     */
    public function normalize(array $raw): array
    {
        $e = ['title' => '', 'status' => '', 'created' => '', 'revue' => '', 'expire' => '', 'tags' => [], 'fields' => []];
        foreach (self::ALIASES as $canon => $aliases) {
            foreach ($aliases as $alias) {
                if (!array_key_exists($alias, $raw)) continue;
                $val = $raw[$alias];
                unset($raw[$alias]);
                if ($canon === 'tags') {
                    $list = is_array($val) ? $val : preg_split('/[,;]/', (string)$val);
                    foreach ($list as $t) {
                        $t = trim(ltrim(trim((string)$t), '#'));
                        if ($t !== '') $e['tags'][] = $t;
                    }
                } elseif (in_array($canon, ['created', 'revue', 'expire'], true)) {
                    if ($e[$canon] === '') $e[$canon] = $this->date(is_array($val) ? '' : $val);
                } elseif ($e[$canon] === '') {
                    $e[$canon] = is_array($val) ? implode(', ', $val) : (string)$val;
                }
            }
        }
        $e['tags'] = array_values(array_unique($e['tags']));
        foreach ($raw as $k => $v) {
            if ($v === '' || $v === []) continue;
            $e['fields'][$k] = $v;
        }
        return $e;
    }

    /**
     * Lit l'en-tête d'un texte de page
     *
     * @param string $text
     * @return array|null entrée normalisée, null s'il n'y a pas d'en-tête
     */
    public function read(string $text): ?array
    {
        $b = $this->split($text);
        return $b === null ? null : $this->normalize($this->parseYaml($b['yaml']));
    }

    // endregion

    // region index

    protected function indexFile(): string
    {
        global $conf;
        return $conf['indexdir'] . '/fmeta.json';
    }

    /** @return array<string,array> id => entrée */
    public function load(): array
    {
        if (self::$index !== null) return self::$index;
        $file = $this->indexFile();
        if (!file_exists($file)) return $this->rebuild();
        $data = json_decode((string)io_readFile($file, false), true);
        self::$index = is_array($data) ? $data : [];
        return self::$index;
    }

    protected function save(array $index): void
    {
        self::$index = $index;
        io_saveFile($this->indexFile(), json_encode($index, JSON_UNESCAPED_UNICODE));
    }

    /**
     * #hashtags écrits dans le corps du texte
     *
     * Les blocs de code, le code en ligne, les liens et les adresses sont ignorés ; un tag est un
     * # collé à un mot commençant par une lettre, jamais « # Titre » ni page#ancre.
     *
     * @return string[]
     */
    public function bodyTags(string $text): array
    {
        $b = $this->split($text);
        if ($b !== null) $text = implode("\n", array_slice(explode("\n", $text), $b['close'] + 1));

        $text = preg_replace(
            ['/^(`{3,}|~{3,}).*?^\1[ \t]*$/ms', '#<(code|file|nowiki)\b[^>]*>.*?</\1>#s', '/%%.*?%%/s', '/`[^`\n]*`/',
             '/\]\([^)\n]*\)/', '/\[\[.*?\]\]/s', '#\b[a-z][a-z0-9+.-]*://\S+#i'],
            ' ',
            $text
        );
        if (!preg_match_all('/(?<![\p{L}\p{N}_&#])#(\p{L}[\p{L}\p{N}_\/-]*)/u', $text, $m)) return [];

        $tags = [];
        foreach ($m[1] as $t) {
            $t = rtrim($t, '-/_');
            if ($t !== '') $tags[mb_strtolower($t)] = $t;
        }
        return array_values($tags);
    }

    /** Construit l'entrée d'index d'une page à partir de son texte, null sans en-tête */
    public function entryFor(string $id, string $text): ?array
    {
        $e = $this->read($text);
        $body = $this->bodyTags($text);
        // une page sans en-tête n'est indexée que si elle contient des #hashtags
        if ($e === null && !$body) return null;
        $hdr = $e !== null;
        $e ??= $this->normalize([]);
        $e['hdr'] = $hdr;
        $known = array_map('mb_strtolower', $e['tags']);
        foreach ($body as $t) {
            if (!in_array(mb_strtolower($t), $known, true)) {
                $e['tags'][] = $t;
                $known[] = mb_strtolower($t);
            }
        }

        if ($e['title'] === '') {
            // le titre se cherche après l'en-tête : ses lignes de commentaire YAML (# ...) ne sont pas des titres
            $sp = $this->split($text);
            $content = ($sp && $sp['close'] !== null)
                ? implode("\n", array_slice(explode("\n", $text), $sp['close'] + 1)) : $text;
            if (preg_match('/^#\s+(.+?)\s*#*\s*$/m', $content, $m) || preg_match('/^={2,6}\s*(.+?)\s*={2,6}\s*$/m', $content, $m)) {
                $e['title'] = trim($m[1]);
            } else {
                $e['title'] = noNS($id);
            }
        }
        $e['mtime'] = (int)@filemtime(wikiFN($id));
        if ($e['created'] === '') {
            $c = (int)p_get_metadata($id, 'date created', METADATA_DONT_RENDER);
            if ($c) $e['created'] = date('Y-m-d', $c);
        }
        return $e;
    }

    /** Met à jour (ou retire) une page de l'index */
    public function indexPage(string $id, ?string $text = null): void
    {
        $index = $this->load();
        $text ??= (string)rawWiki($id);
        $e = trim($text) === '' ? null : $this->entryFor($id, $text);
        if ($e === null) {
            unset($index[$id]);
        } else {
            $index[$id] = $e;
        }
        $this->save($index);
    }

    /** Reconstruit tout l'index en relisant les pages */
    public function rebuild(): array
    {
        global $conf;
        $pages = [];
        search($pages, $conf['datadir'], 'search_allpages', ['skipacl' => true], '');
        $index = [];
        foreach ($pages as $p) {
            $e = $this->entryFor($p['id'], (string)rawWiki($p['id']));
            if ($e !== null) $index[$p['id']] = $e;
        }
        $this->save($index);
        return $index;
    }

    /**
     * Fiches visibles par l'utilisateur courant, filtrées
     *
     * Filtres : tag, status, created, revue, expire (préfixe de date accepté : 2026-11),
     * ou n'importe quel autre champ de l'en-tête (nature, projet...).
     *
     * @param array<string,string> $filters
     * @return array[] entrées avec 'id' et 'eff' (statut effectif)
     */
    public function query(array $filters = []): array
    {
        $life = plugin_load('helper', 'fmlife');
        $out = [];
        foreach ($this->load() as $id => $e) {
            if (auth_quickaclcheck($id) < AUTH_READ) continue;
            $e['id'] = $id;
            $e['eff'] = $life ? $life->effective($e) : ($e['status'] ?? '');
            foreach ($filters as $k => $v) {
                if ($v === '' || $v === null) continue;
                if (!$this->matches($e, $k, mb_strtolower((string)$v))) continue 2;
            }
            $out[] = $e;
        }
        usort($out, static function ($a, $b) {
            $da = $a['revue'] ?: $a['created'];
            $db = $b['revue'] ?: $b['created'];
            return [$db, $a['title']] <=> [$da, $b['title']];
        });
        return $out;
    }

    protected function matches(array $e, string $field, string $v): bool
    {
        switch ($field) {
            case 'tag':
                return in_array($v, array_map('mb_strtolower', $e['tags']), true);
            case 'status':
                return mb_strtolower($e['eff']) === $v || mb_strtolower($e['status']) === $v;
            case 'created':
            case 'revue':
            case 'expire':
                return $e[$field] !== '' && str_starts_with($e[$field], $v);
            default:
                $val = $e['fields'][$field] ?? null;
                if ($val === null) return false;
                foreach ((array)$val as $x) {
                    if (mb_strtolower((string)$x) === $v) return true;
                }
                return false;
        }
    }

    /** @return array<string,int> tag => nombre de fiches visibles, trié par nom */
    public function tagCounts(): array
    {
        $counts = [];
        foreach ($this->query() as $e) {
            foreach ($e['tags'] as $t) {
                $k = mb_strtolower($t);
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        ksort($counts);
        return $counts;
    }

    // endregion

    // region rendu

    /** Adresse de la liste des fiches ayant cette valeur de champ */
    public function url(string $field, string $value): string
    {
        global $ID;
        return hsc(wl($ID ?: 'start', ['do' => 'fmlist', 'field' => $field, 'value' => $value], false, '&'));
    }

    public function dateLabel(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : $d;
    }

    /** Lien vers la liste des fiches de cette date */
    public function dateLink(string $field, string $d): string
    {
        if ($d === '') return '';
        return '<a class="fm-date" href="' . $this->url($field, $d) . '" title="' .
            hsc($this->getLang('same_date')) . '">' . hsc($this->dateLabel($d)) . '</a>';
    }

    public function tagLink(string $tag): string
    {
        return '<a class="fm-tag" href="' . $this->url('tag', $tag) . '">#' . hsc($tag) . '</a>';
    }

    public function badge(array $e): string
    {
        $life = plugin_load('helper', 'fmlife');
        $raw = $e['eff'] ?? $e['status'] ?? '';
        if ($raw === '') return '';
        if ($life) return $life->badge($raw, $e['status'] ?? null);
        return '<span class="fm-badge">' . hsc($raw) . '</span>';
    }

    /**
     * La carte d'en-tête affichée en haut d'une page
     *
     * @param array $e entrée normalisée
     * @return string HTML
     */
    public function card(array $e): string
    {
        $life = plugin_load('helper', 'fmlife');
        $e['eff'] = $life ? $life->effective($e) : $e['status'];

        $h = '<div class="plugin_fmeta">';
        $h .= '<div class="fm-head">' . $this->badge($e);
        foreach (['nature'] as $k) {
            if (!empty($e['fields'][$k])) {
                $h .= ' <a class="fm-chip" href="' . $this->url($k, (string)$e['fields'][$k]) . '">' . hsc((string)$e['fields'][$k]) . '</a>';
            }
        }
        $h .= '</div>';

        $rows = '';
        foreach (['created', 'revue', 'expire'] as $k) {
            $val = $e[$k];
            if ($k === 'expire' && $val === '' && $life) {
                $val = $life->expiry($e);
                if ($val !== '') $rows .= '<dt>' . hsc($this->getLang("f_$k")) . '</dt><dd>' . $this->dateLink($k, $val) . ' <em>(' . hsc($this->getLang('computed')) . ')</em></dd>';
                continue;
            }
            if ($val !== '') $rows .= '<dt>' . hsc($this->getLang("f_$k")) . '</dt><dd>' . $this->dateLink($k, $val) . '</dd>';
        }
        foreach ($e['fields'] as $k => $v) {
            if ($k === 'nature') continue;
            $label = $this->getLang("f_$k") ?: ucfirst($k);
            $cells = [];
            foreach ((array)$v as $x) {
                $cells[] = '<a href="' . $this->url($k, (string)$x) . '">' . hsc((string)$x) . '</a>';
            }
            $rows .= '<dt>' . hsc($label) . '</dt><dd>' . implode(', ', $cells) . '</dd>';
        }
        if ($rows !== '') $h .= '<dl class="fm-fields">' . $rows . '</dl>';

        if ($e['tags']) {
            $h .= '<ul class="fm-tags">';
            foreach ($e['tags'] as $t) $h .= '<li>' . $this->tagLink($t) . '</li>';
            $h .= '</ul>';
        }

        if ($life) {
            foreach ($life->alerts($e) as $a) {
                $h .= '<div class="fm-alert fm-' . $a['level'] . '">' . hsc($a['text']) . '</div>';
            }
        }
        return $h . '</div>';
    }

    /**
     * Tableau de fiches
     *
     * @param array[] $rows résultat de query()
     * @param bool $alerts ajoute une colonne d'alertes (fmlife)
     * @return string HTML
     */
    public function table(array $rows, bool $alerts = false): string
    {
        if (!$rows) return '<p>' . hsc($this->getLang('none')) . '</p>';
        $life = $alerts ? plugin_load('helper', 'fmlife') : null;

        $h = '<table class="inline plugin_fmeta_table"><thead><tr><th>' . hsc($this->getLang('col_page')) . '</th><th>' .
            hsc($this->getLang('f_status')) . '</th><th>' . hsc($this->getLang('f_created')) . '</th><th>' .
            hsc($this->getLang('f_revue')) . '</th><th>' . hsc($this->getLang('f_expire')) . '</th><th>' .
            hsc($this->getLang('f_tags')) . '</th>' . ($life ? '<th>' . hsc($this->getLang('col_alert')) . '</th>' : '') .
            '</tr></thead><tbody>';
        foreach ($rows as $e) {
            $tags = implode(' ', array_map([$this, 'tagLink'], $e['tags']));
            $h .= '<tr><td><a href="' . hsc(wl($e['id'])) . '">' . hsc($e['title']) . '</a></td><td>' . $this->badge($e) .
                '</td><td>' . $this->dateLink('created', $e['created']) . '</td><td>' . $this->dateLink('revue', $e['revue']) .
                '</td><td>' . $this->dateLink('expire', $e['expire']) . '</td><td>' . $tags . '</td>';
            if ($life) {
                $h .= '<td>' . hsc(implode(' ; ', array_column($life->alerts($e), 'text'))) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    // endregion
}
