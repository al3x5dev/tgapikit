<?php

namespace Mk4U\TgApiKit;

class Scrapper
{
    public static function data(): array
    {
        $telegramUrl = 'https://core.telegram.org/bots/api';

        $html = file_get_contents($telegramUrl);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $data = $xpath->query('//div[@id="dev_page_content"]')->item(0);

        $releaseTag = $xpath->query('//h4')->item(0)->textContent;
        $version = $xpath->query('//p/strong')->item(0)->textContent;

        $result = [
            'version' => $version,
            'release_date' => $releaseTag,
            'methods' => [],
            'types' => [],
        ];

        $currentType = $currentName = '';

        foreach ($data->childNodes as $node) {
            if ($node->nodeName === 'h3' || $node->nodeName === 'hr') {
                $currentType = '';
                $currentName = '';
            }

            if ($node->nodeName === 'h4') {
                $a = $node->getElementsByTagName('a')->item(0);
                $name = $a ? $a->getAttribute('name') : '';

                if ($name && strpos($name, '-') !== false) {
                    $currentName = '';
                    $currentType = '';
                    continue;
                }

                $currentType = ctype_upper($node->textContent[0]) ? 'types' : 'methods';
                $currentName = $node->textContent;

                if (!isset($result[$currentType][$currentName])) {
                    $result[$currentType][$currentName] = [];
                }

                if ($a && $href = $a->getAttribute('href')) {
                    $result[$currentType][$currentName]['href'] = $telegramUrl . $href;
                }

                continue;
            }

            if (!$currentType || !$currentName) {
                continue;
            }

            if ($node->nodeName === 'p') {
                $result[$currentType][$currentName]['description'][] = $node->textContent;
            }

            if ($node->nodeName === 'table') {
                $keys = [];
                $thead = $node->getElementsByTagName('thead')->item(0);
                if ($thead) {
                    foreach ($thead->getElementsByTagName('th') as $th) {
                        $keys[] = $th->textContent;
                    }
                }

                $values = [];
                $tbody = $node->getElementsByTagName('tbody')->item(0);
                if ($tbody) {
                    foreach ($tbody->getElementsByTagName('tr') as $tr) {
                        $row = [];
                        foreach ($tr->getElementsByTagName('td') as $td) {
                            $row[] = $td->textContent;
                        }
                        $values[] = $row;
                    }
                }

                $fields = array_values(array_map(
                    fn($n) => static::prepareFieldData($keys, $n),
                    array_filter($values)
                ));

                $keys = array_map(fn(array $field) => $field['parameter'] ?? $field['field'], $fields);
                $fields = array_map(function (array $field) {
                    unset($field['parameter'], $field['field']);
                    return $field;
                }, $fields);

                $result[$currentType][$currentName][$currentType === 'methods' ? 'parameters' : 'fields'] = array_combine($keys, $fields);
            }

            if ($node->nodeName === 'ul') {
                foreach ($node->getElementsByTagName('li') as $li) {
                    $a = $li->getElementsByTagName('a')->item(0);
                    if ($a) {
                        $type = $a->textContent;
                        $result[$currentType][$currentName]['subtypes'][] = $type;
                        $result[$currentType][$type]['extends'] = $currentName;
                    }
                }
            }

            if ($currentType === 'methods' && isset($result[$currentType][$currentName]['description'])) {
                $description = implode(PHP_EOL, $result[$currentType][$currentName]['description']);
                static::extractReturnTypes($currentType, $currentName, $description, $result);
            }
        }

        return $result;
    }

    protected static function prepareFieldData(array $keys, array $data): array
    {
        $data = array_change_key_case(array_combine($keys, $data));

        if (isset($data['type'])) {
            $data['type'] = static::cleanType($data['type']);
        }

        if (isset($data['required'])) {
            $data['required'] = $data['required'] === 'Yes';
        }

        return $data;
    }

    protected static function extractReturnTypes($currentType, $currentName, string $description, &$items): void
    {
        $returns = [];
        $sentences = [];

        foreach ([
            '/\b(Returns?\b[^.]*)/',
            '/\b(On success,\s*[^.]*)/i',
            '/([^.]*\b(?:is|are)\s+returned[^.]*)/',
        ] as $pattern) {
            if (preg_match_all($pattern, $description, $matches)) {
                foreach ($matches[1] as $sentence) {
                    $sentence = trim($sentence);

                    if ($sentence !== '') {
                        $sentences[$sentence] = true;
                    }
                }
            }
        }

        foreach (array_keys($sentences) as $sentence) {
            if (
                preg_match('/^an?\s+error\b/i', $sentence) ||
                preg_match('/\breturns?\s+an?\s+error\b/i', $sentence)
            ) {
                continue;
            }

            // "Array of X" / "list of X"
            if (preg_match('/(?:[Aa]rray|[Ll]ist) of\s+([A-Z]\w*)/', $sentence, $match)) {
                $returns[] = 'Array<' . $match[1] . '>';
                continue;
            }

            $found = false;

            // "the new invite link as ChatInviteLink"
            if (preg_match('/\bas\s+([A-Z]\w*)/', $sentence, $match)) {
                $returns[] = $match[1];
                $found = true;
            }

            // "a File object"
            if (preg_match_all('/([A-Z]\w*)\s+objects?\b/', $sentence, $matches)) {
                foreach ($matches[1] as $type) {
                    $returns[] = $type;
                    $found = true;
                }
            }

            // "the Message is returned" / "otherwise True is returned"
            if (preg_match_all('/([A-Z]\w*)\s+(?:is|are)\s+returned\b/', $sentence, $matches)) {
                foreach ($matches[1] as $type) {
                    $returns[] = $type;
                    $found = true;
                }
            }

            // "Returns True on success"
            if (preg_match_all('/\b(True|False)\b/', $sentence, $matches)) {
                foreach ($matches[1] as $type) {
                    $returns[] = $type;
                    $found = true;
                }
            }

            if ($found) {
                continue;
            }

            // "Returns BotName on success" / "Returns the MessageId ..."
            if (preg_match('/\bReturns?\s+(?:(?:the|a|an)\s+)?([A-Z]\w*)\b/', $sentence, $match)) {
                if (!in_array($match[1], ['Array', 'True', 'False', 'Telegram', 'Bot', 'API'], true)) {
                    $returns[] = $match[1];
                }
            } elseif (preg_match('/\bReturns?\s+the\s+[a-z]+\s+([A-Z]\w*)\b/', $sentence, $match)) {
                if (!in_array($match[1], ['Array', 'True', 'False', 'Telegram', 'Bot', 'API'], true)) {
                    $returns[] = $match[1];
                }
            } elseif (preg_match('/\bOn success,\s*(?:(?:the|a|an)\s+)?([A-Z]\w*)\b/i', $sentence, $match)) {
                if (!in_array($match[1], ['Array', 'True', 'False', 'Telegram', 'Bot', 'API'], true)) {
                    $returns[] = $match[1];
                }
            }
        }

        if ($returns === []) {
            return;
        }

        $items[$currentType][$currentName]['returns'] = array_values(array_unique($returns));
    }

    protected static function cleanType(string $type): array
    {
        if ($isArray = (strpos($type, 'Array of ') === 0)) {
            $type = substr($type, strlen('Array of '));
        }

        $fixed_ors = array_map('trim', explode(' or ', $type));
        $fixed_ands = [];
        foreach ($fixed_ors as $fo) {
            $fixed_ands = array_merge($fixed_ands, array_map('trim', explode(' and ', $fo)));
        }
        $fixed_commas = [];
        foreach ($fixed_ands as $fa) {
            $fixed_commas = array_merge($fixed_commas, array_map('trim', explode(', ', $fa)));
        }

        return array_map(fn(string $x) => $isArray ? 'Array<' . static::cleanType($x)[0] . '>' : $x, $fixed_commas);
    }
}
