<?php

namespace Mk4U\TgApiKit\Generator;

/**
 * Genera el código de resolución de subtipos para las entidades padre.
 *
 * No escribe archivos ni mantiene un registro aparte: Entities::generate()
 * le pasa los metadatos scrapeados y recibe, por cada padre, el código de
 * resolve(), de create() y sus constantes.
 *
 * La documentación de Telegram publica, para cada padre, la lista de hijos
 * y, en cada hijo, el campo discriminador con su valor ("always “data”" o
 * "must be photo"). Con esa información se deduce el resto.
 *
 * Los pocos padres que no usan un discriminador simple se resuelven con
 * estrategias específicas declaradas en SPECIAL_STRATEGIES.
 */
class SubTypes
{
    /**
     * Padres que no se pueden resolver con un match sobre un campo.
     *
     * presence: un hijo tiene un campo que el otro no (MaybeInaccessibleMessage).
     * inline:   varios hijos comparten el mismo "type" (InlineQueryResult).
     * fields:   no hay campo discriminador; se detecta por campos presentes
     *           (InputMessageContent).
     */
    private const SPECIAL_STRATEGIES = [
        'MaybeInaccessibleMessage' => 'presence',
        'InlineQueryResult' => 'inline',
        'InputMessageContent' => 'fields',
    ];

    /**
     * Nombres de constantes que ya formaban parte de la API pública y que no
     * queremos romper al regenerar las entidades.
     */
    private const CONSTANT_ALIASES = [
        'date_time' => 'DATETIME',
        'blockquote' => 'BLOCK_QUOTATION',
        'expandable_blockquote' => 'EXPANDABLE_BLOCK_QUOTATION',
        'pullquote' => 'PULL_QUOTATION',
        'pre' => 'PREFORMATTED',
    ];

    /**
     * Código de resolución por entidad padre.
     *
     * @return array<string, array{resolve: string, factory: string, constants: ?string}>
     */
    public static function map(array $types): array
    {
        $map = [];

        foreach (self::build($types) as $parent) {
            $map[$parent['name']] = [
                'resolve' => self::resolveBody($parent),
                'factory' => self::factoryBody($parent),
                'constants' => self::constantsBody($parent),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function build(array $types): array
    {
        $parents = [];

        foreach ($types as $name => $typeData) {
            if (empty($typeData['subtypes'])) {
                continue;
            }

            $children = array_values(array_unique(array_filter(
                $typeData['subtypes'],
                static fn (string $child): bool => isset($types[$child])
            )));

            if ($children === []) {
                continue;
            }

            $strategy = self::SPECIAL_STRATEGIES[$name] ?? 'match';

            try {
                $parents[] = match ($strategy) {
                    'presence' => self::buildPresence($name, $children, $types),
                    'inline' => self::buildInline($name, $children, $types),
                    'fields' => self::buildFields($name, $children, $types),
                    default => self::buildMatch($name, $children, $types),
                };
            } catch (\Throwable $e) {
                // Si la API cambia la forma de un padre conocido, no rompemos la
                // actualización: intentamos detectar los hijos por campos.
                try {
                    $parents[] = self::buildFields($name, $children, $types);
                    fwrite(STDERR, "SubType: {$name} se resolverá por campos ({$e->getMessage()}).\n");
                } catch (\Throwable $fallbackError) {
                    fwrite(STDERR, "SubType: se omite {$name}: {$fallbackError->getMessage()}.\n");
                }
            }
        }

        return $parents;
    }

    /**
     * Estrategia normal: todos los hijos comparten un campo con un valor único.
     */
    private static function buildMatch(string $name, array $children, array $types): array
    {
        $field = self::detectDiscriminatorField($children, $types);
        $map = [];
        $seen = [];

        foreach ($children as $child) {
            $value = self::extractDiscriminatorValue($types[$child], $field);

            if ($value === null) {
                $value = self::guessValueFromClassName($name, $child);
                fwrite(STDERR, "SubType: no se encontró el valor de '{$field}' para {$child}; se usa '{$value}'.\n");
            }

            if (isset($seen[$value])) {
                throw new \RuntimeException(
                    "SubType: el valor '{$value}' está repetido en {$name}. " .
                    'Añade una estrategia específica en SubTypes::SPECIAL_STRATEGIES.'
                );
            }

            $seen[$value] = true;
            $map[] = ['value' => $value, 'class' => $child];
        }

        return [
            'name' => $name,
            'children' => $children,
            'strategy' => 'match',
            'field' => $field,
            'map' => $map,
            'constants' => self::makeConstants($field, array_column($map, 'value')),
        ];
    }

    /**
     * Estrategia para padres sin campo discriminador pero con dos hijos
     * distinguibles por la presencia de un campo (MaybeInaccessibleMessage).
     */
    private static function buildPresence(string $name, array $children, array $types): array
    {
        $positive = null;
        $negative = null;

        foreach ($children as $child) {
            if (isset($types[$child]['fields']['from'])) {
                $positive = $child;
            } else {
                $negative = $child;
            }
        }

        if (count($children) !== 2 || $positive === null || $negative === null) {
            throw new \RuntimeException(
                "SubType: la estrategia 'presence' de {$name} espera exactamente dos hijos, " .
                'uno con el campo "from" y otro sin él.'
            );
        }

        return [
            'name' => $name,
            'children' => $children,
            'strategy' => 'presence',
            'field' => 'from',
            'positive' => $positive,
            'negative' => $negative,
            'constants' => self::makeConstants('type', [
                self::snakeCase($positive),
                self::snakeCase($negative),
            ]),
        ];
    }

    /**
     * Estrategia para InlineQueryResult: varios tipos comparten el mismo
     * "type" y se distinguen por la presencia de un campo *_url.
     */
    private static function buildInline(string $name, array $children, array $types): array
    {
        $groups = [];

        foreach ($children as $child) {
            $value = self::extractDiscriminatorValue($types[$child], 'type');

            if ($value === null) {
                throw new \RuntimeException("SubType: no se pudo leer el tipo de {$child}.");
            }

            $groups[$value][] = $child;
        }

        $rendered = [];

        foreach ($groups as $value => $classes) {
            if (count($classes) === 1) {
                $rendered[] = [
                    'value' => $value,
                    'variants' => [['class' => $classes[0], 'url' => null]],
                ];
                continue;
            }

            $nonCached = array_values(array_filter(
                $classes,
                static fn (string $class): bool => !str_contains($class, 'Cached')
            ));
            $cached = array_values(array_filter(
                $classes,
                static fn (string $class): bool => str_contains($class, 'Cached')
            ));

            if (count($nonCached) !== 1 || count($cached) !== 1) {
                throw new \RuntimeException(
                    "SubType: no se puede distinguir el grupo '{$value}' de {$name}. " .
                    'Añade una estrategia específica en SubTypes::SPECIAL_STRATEGIES.'
                );
            }

            $urlField = self::findUrlField($types[$nonCached[0]]);

            if ($urlField === null) {
                throw new \RuntimeException("SubType: {$nonCached[0]} no tiene un campo *_url.");
            }

            $rendered[] = [
                'value' => $value,
                'variants' => [
                    ['class' => $nonCached[0], 'url' => $urlField],
                    ['class' => $cached[0], 'url' => null],
                ],
            ];
        }

        return [
            'name' => $name,
            'children' => $children,
            'strategy' => 'inline',
            'field' => 'type',
            'groups' => $rendered,
            'constants' => self::makeConstants('type', array_keys($groups)),
        ];
    }

    /**
     * Estrategia para InputMessageContent: no existe un campo común que
     * identifique al subtipo, así que se detecta por los campos requeridos.
     *
     * Para cada hijo se busca el primer campo requerido que no aparezca en
     * ningún otro hijo. Si no existe, se usan todos sus campos requeridos.
     * Los hijos con un campo exclusivo se evalúan antes que los genéricos.
     */
    private static function buildFields(string $name, array $children, array $types): array
    {
        $requiredByChild = [];

        foreach ($children as $child) {
            $requiredByChild[$child] = self::requiredFields($types[$child]);
        }

        $occurrences = [];
        foreach ($requiredByChild as $fields) {
            foreach ($fields as $field) {
                $occurrences[$field] = ($occurrences[$field] ?? 0) + 1;
            }
        }

        $signatures = [];

        foreach ($children as $child) {
            $required = $requiredByChild[$child];

            // Sin campos no hay forma fiable de detectar el subtipo.
            if ($required === []) {
                continue;
            }

            $unique = array_values(array_filter(
                $required,
                static fn (string $field): bool => ($occurrences[$field] ?? 0) === 1
            ));

            $signatures[] = [
                'class' => $child,
                'fields' => $unique !== [] ? [$unique[0]] : $required,
                'specific' => $unique !== [],
            ];
        }

        if ($signatures === []) {
            throw new \RuntimeException("{$name} no tiene hijos con campos detectables.");
        }

        usort(
            $signatures,
            static fn (array $a, array $b): int => $b['specific'] <=> $a['specific']
        );

        return [
            'name' => $name,
            'children' => $children,
            'strategy' => 'fields',
            'signatures' => $signatures,
            'constants' => [],
        ];
    }

    private static function detectDiscriminatorField(array $children, array $types): string
    {
        foreach (['type', 'status', 'source', 'kind'] as $field) {
            $values = [];

            foreach ($children as $child) {
                $value = self::extractDiscriminatorValue($types[$child], $field);

                if ($value === null) {
                    continue 2;
                }

                $values[] = $value;
            }

            if (count(array_unique($values)) === count($values)) {
                return $field;
            }
        }

        throw new \RuntimeException(
            'SubType: no se pudo detectar el campo discriminador. ' .
            'Añade una estrategia específica en SubTypes::SPECIAL_STRATEGIES.'
        );
    }

    private static function extractDiscriminatorValue(array $typeData, string $field): ?string
    {
        $description = $typeData['fields'][$field]['description'] ?? '';

        if (preg_match('/always\s+[“"]([^”"]+)[”"]/u', $description, $match)) {
            return $match[1];
        }

        if (preg_match('/must be\s+[“"]([^”"]+)[”"]/u', $description, $match)) {
            return $match[1];
        }

        if (preg_match('/must be\s+([a-z0-9_]+)/i', $description, $match)) {
            return strtolower($match[1]);
        }

        return null;
    }

    private static function guessValueFromClassName(string $parent, string $child): string
    {
        $suffix = str_starts_with($child, $parent)
            ? substr($child, strlen($parent))
            : $child;

        return self::snakeCase($suffix);
    }

    private static function snakeCase(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * Campos requeridos según la descripción ("Optional." al inicio = opcional).
     * Si no hay descripciones utilizables, se usan todos los campos.
     *
     * @return string[]
     */
    private static function requiredFields(array $typeData): array
    {
        $fields = $typeData['fields'] ?? [];
        $required = [];

        foreach ($fields as $field => $meta) {
            $description = $meta['description'] ?? '';

            if (!preg_match('/^\s*Optional\./i', $description)) {
                $required[] = $field;
            }
        }

        return $required !== [] ? $required : array_keys($fields);
    }

    private static function findUrlField(array $typeData): ?string
    {
        foreach (self::requiredFields($typeData) as $field) {
            if (str_ends_with($field, '_url')) {
                return $field;
            }
        }

        foreach (array_keys($typeData['fields'] ?? []) as $field) {
            if (str_ends_with($field, '_url')) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param string[] $values
     * @return array<int, array{name: string, value: string}>
     */
    private static function makeConstants(string $field, array $values): array
    {
        $constants = [];

        foreach ($values as $value) {
            $name = self::constantName($field, $value);

            // Evita que dos valores distintos compartan constante.
            if (isset($constants[$name]) && $constants[$name]['value'] !== $value) {
                $suffix = 2;

                while (isset($constants[$name . '_' . $suffix])) {
                    $suffix++;
                }

                $name .= '_' . $suffix;
            }

            $constants[$name] = ['name' => $name, 'value' => $value];
        }

        return array_values($constants);
    }

    private static function constantName(string $field, string $value): string
    {
        return strtoupper($field) . '_' . self::constantSuffix($value);
    }

    private static function constantSuffix(string $value): string
    {
        if (isset(self::CONSTANT_ALIASES[$value])) {
            return self::CONSTANT_ALIASES[$value];
        }

        $suffix = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $value));
        $suffix = trim($suffix, '_');

        if ($suffix === '') {
            return 'VALUE';
        }

        return ctype_digit($suffix[0]) ? '_' . $suffix : $suffix;
    }

    private static function constantsBody(array $parent): ?string
    {
        if ($parent['constants'] === []) {
            return null;
        }

        $lines = [];

        foreach ($parent['constants'] as $constant) {
            $lines[] = 'public const ' . $constant['name'] . ' = ' . var_export($constant['value'], true) . ';';
        }

        return implode("\n    ", $lines);
    }

    private static function factoryBody(array $parent): string
    {
        return match ($parent['strategy']) {
            'presence' => self::factoryPresenceBody($parent),
            'inline' => self::factoryInlineBody($parent),
            'fields' => self::factoryFieldsBody($parent),
            default => self::factoryMatchBody($parent),
        };
    }

    private static function resolveBody(array $parent): string
    {
        return match ($parent['strategy']) {
            'presence' => self::resolvePresenceBody($parent),
            'inline' => self::resolveInlineBody($parent),
            'fields' => self::resolveFieldsBody($parent),
            default => self::resolveMatchBody($parent),
        };
    }

    private static function factoryMatchBody(array $parent): string
    {
        $name = $parent['name'];
        $field = $parent['field'];

        $body = "    /**\n";
        $body .= "     * Factory: creates the correct subclass based on {$field}\n";
        $body .= "     *\n";
        $body .= "     * @param array \$data Must contain '{$field}' key\n";
        $body .= "     * @return Entity\n";
        $body .= "     * @throws \\InvalidArgumentException\n";
        $body .= "     */\n";
        $body .= "    public static function create(array \$data): Entity\n";
        $body .= "    {\n";
        $body .= "        return match(\$data['{$field}'] ?? null) {\n";

        foreach ($parent['map'] as $entry) {
            $body .= '            self::' . self::constantName($field, $entry['value']) . " => new {$entry['class']}(\$data),\n";
        }

        $body .= "            default => throw new \\InvalidArgumentException('Unknown {$name} {$field}: ' . (\$data['{$field}'] ?? 'null')),\n";
        $body .= "        };\n";
        $body .= "    }";

        return $body;
    }

    private static function factoryPresenceBody(array $parent): string
    {
        $field = $parent['field'];

        $body = "    /**\n";
        $body .= "     * Factory: creates the correct subclass based on data\n";
        $body .= "     *\n";
        $body .= "     * @param array \$data\n";
        $body .= "     * @return Entity\n";
        $body .= "     */\n";
        $body .= "    public static function create(array \$data): Entity\n";
        $body .= "    {\n";
        $body .= "        return isset(\$data['{$field}'])\n";
        $body .= "            ? new {$parent['positive']}(\$data)\n";
        $body .= "            : new {$parent['negative']}(\$data);\n";
        $body .= "    }";

        return $body;
    }

    private static function factoryInlineBody(array $parent): string
    {
        $body = "    /**\n";
        $body .= "     * Factory: creates the correct subclass based on type\n";
        $body .= "     *\n";
        $body .= "     * @param array \$data Must contain 'type' key\n";
        $body .= "     * @return Entity\n";
        $body .= "     * @throws \\InvalidArgumentException\n";
        $body .= "     */\n";
        $body .= "    public static function create(array \$data): Entity\n";
        $body .= "    {\n";
        $body .= "        \$type = \$data['type'] ?? null;\n";
        $body .= "        return match(\$type) {\n";

        foreach ($parent['groups'] as $group) {
            $constant = 'self::' . self::constantName('type', $group['value']);
            $variants = $group['variants'];

            if (count($variants) === 1) {
                $body .= "            {$constant} => new {$variants[0]['class']}(\$data),\n";
                continue;
            }

            $nonCached = $variants[0];
            $cached = $variants[1];

            $body .= "            {$constant} => isset(\$data['{$nonCached['url']}'])\n";
            $body .= "                ? new {$nonCached['class']}(\$data)\n";
            $body .= "                : new {$cached['class']}(\$data),\n";
        }

        $body .= "            default => throw new \\InvalidArgumentException('Unknown InlineQueryResult type: ' . (\$type ?? 'null')),\n";
        $body .= "        };\n";
        $body .= "    }";

        return $body;
    }

    private static function factoryFieldsBody(array $parent): string
    {
        $name = $parent['name'];

        $body = "    /**\n";
        $body .= "     * Factory: creates the correct subclass based on the provided fields\n";
        $body .= "     *\n";
        $body .= "     * @param array \$data\n";
        $body .= "     * @return Entity\n";
        $body .= "     * @throws \\InvalidArgumentException\n";
        $body .= "     */\n";
        $body .= "    public static function create(array \$data): Entity\n";
        $body .= "    {\n";
        $body .= "        return match(true) {\n";

        foreach ($parent['signatures'] as $signature) {
            $conditions = implode(', ', array_map(
                static fn (string $field): string => "\$data['{$field}']",
                $signature['fields']
            ));

            $body .= "            isset({$conditions}) => new {$signature['class']}(\$data),\n";
        }

        $body .= "            default => throw new \\InvalidArgumentException('Unknown {$name}: no valid property found'),\n";
        $body .= "        };\n";
        $body .= "    }";

        return $body;
    }

    private static function resolveMatchBody(array $parent): string
    {
        $name = $parent['name'];
        $field = $parent['field'];

        $body = "public function resolve(): Entity\n";
        $body .= "    {\n";
        $body .= "        return match(\$this->{$field}) {\n";

        foreach ($parent['map'] as $entry) {
            $body .= '            ' . var_export($entry['value'], true) . " => new {$entry['class']}(\$this->properties),\n";
        }

        $body .= "            default => throw new \\InvalidArgumentException('Unknown {$name} {$field}: ' . \$this->{$field}),\n";
        $body .= "        };\n";
        $body .= "    }";

        return $body;
    }

    private static function resolvePresenceBody(array $parent): string
    {
        $field = $parent['field'];

        $body = "public function resolve(): Entity\n";
        $body .= "    {\n";
        $body .= "        return \$this->hasProperty('{$field}')\n";
        $body .= "            ? new {$parent['positive']}(\$this->properties)\n";
        $body .= "            : new {$parent['negative']}(\$this->properties);\n";
        $body .= "    }";

        return $body;
    }

    private static function resolveInlineBody(array $parent): string
    {
        $body = "public function resolve(): Entity\n";
        $body .= "    {\n";
        $body .= "        return match(\$this->type) {\n";

        foreach ($parent['groups'] as $group) {
            $variants = $group['variants'];

            if (count($variants) === 1) {
                $body .= '            ' . var_export($group['value'], true) . " => new {$variants[0]['class']}(\$this->properties),\n";
                continue;
            }

            $nonCached = $variants[0];
            $cached = $variants[1];

            $body .= '            ' . var_export($group['value'], true) . " => \$this->hasProperty('{$nonCached['url']}')\n";
            $body .= "                ? new {$nonCached['class']}(\$this->properties)\n";
            $body .= "                : new {$cached['class']}(\$this->properties),\n";
        }

        $body .= "            default => throw new \\InvalidArgumentException('Unknown InlineQueryResult type: ' . \$this->type),\n";
        $body .= "        };\n";
        $body .= "    }";

        return $body;
    }

    private static function resolveFieldsBody(array $parent): string
    {
        $name = $parent['name'];

        $body = "public function resolve(): Entity\n";
        $body .= "    {\n";

        foreach ($parent['signatures'] as $signature) {
            $fields = $signature['fields'];

            if (count($fields) === 1) {
                $field = $fields[0];
                $body .= "        if (\$this->hasProperty('{$field}')) {\n";
            } else {
                $body .= "        if (\n";
                $last = count($fields) - 1;

                foreach ($fields as $index => $field) {
                    $body .= "            \$this->hasProperty('{$field}')";
                    $body .= $index === $last ? "\n" : " &&\n";
                }

                $body .= "        ) {\n";
            }

            $body .= "            return new {$signature['class']}(\$this->properties);\n";
            $body .= "        }\n";
        }

        $body .= "        throw new \\InvalidArgumentException('Unknown {$name}');\n";
        $body .= "    }";

        return $body;
    }
}
