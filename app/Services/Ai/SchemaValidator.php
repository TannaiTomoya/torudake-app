<?php

namespace App\Services\Ai;

/**
 * AI の出力を、タスクの JSON スキーマ（type・properties・required・items・enum・additionalProperties）で確かめる。
 */
class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> 違っていた所。空なら合っている
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        $types = $schema['type'] ?? null;
        if ($types !== null) {
            $types = is_array($types) ? $types : [$types];
            if (! $this->matchesAnyType($value, $types)) {
                return ["{$path} の型が違います"];
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} の値が選択肢にありません";
        }

        $isObjectSchema = isset($schema['properties']) || in_array('object', (array) ($schema['type'] ?? []), true);

        if ($isObjectSchema && is_array($value) && ($value === [] || ! array_is_list($value))) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $key) {
                if (is_string($key) && ! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key} がありません";
                }
            }

            foreach ($value as $key => $child) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $errors = [...$errors, ...$this->validate($child, $properties[$key], "{$path}.{$key}")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key} は要りません";
                }
            }
        } elseif (is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $child) {
                $errors = [...$errors, ...$this->validate($child, $schema['items'], "{$path}[{$index}]")];
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
