<?php

declare(strict_types=1);

namespace Wireframe\Framework;

use Wireframe\Framework\Fields\FieldRegistry;
use Rakit\Validation\Validator as RakitValidator;

/**
 * Validates a settings payload against field definitions.
 *
 * Delegates default rules and structural validation to per-type
 * field handlers via the FieldRegistry.
 */
final class Validator
{
    private static ?RakitValidator $rakit = null;

    private static function rakit(): RakitValidator
    {
        if (self::$rakit === null) {
            self::$rakit = new RakitValidator();
            self::$rakit->setTranslations([
                'required' => 'This field is required.',
                'email'    => 'Please enter a valid email address.',
                'url'      => 'Please enter a valid URL.',
                'numeric'  => 'This must be a number.',
                'integer'  => 'This must be a whole number.',
                'min'      => 'This must be at least :min characters.',
                'max'      => 'This may not be longer than :max characters.',
                'in'       => 'Selected value is not allowed.',
                'between'  => 'This must be between :min and :max.',
                'regex'    => 'The format is invalid.',
            ]);
        }

        return self::$rakit;
    }

    /**
     * @return array{errors: array<string, string|array>, clean: array<string, mixed>}
     */
    public static function validate(array $payload, array $fields, array $visibility = []): array
    {
        $registry = FieldRegistry::instance();
        $errors   = [];
        $clean    = [];

        foreach ($fields as $fieldId => $fieldConfig) {
            if (str_contains($fieldId, '.')) {
                continue;
            }

            if (!empty($visibility) && !($visibility[$fieldId] ?? true)) {
                continue;
            }

            $type    = $fieldConfig['type'] ?? 'text';
            $handler = $registry->get($type);
            $args    = $fieldConfig['args'] ?? [];
            $value   = $payload[$fieldId] ?? null;

            if ($handler::isStateless()) {
                continue;
            }

            // Rakit rule-string validation.
            $isRequired = !empty($fieldConfig['required']);
            $isEmpty    = $value === null || $value === '' || $value === [];
            $ruleString = self::buildRuleString($handler, $fieldConfig);

            if ($ruleString !== '' && ($isRequired || !$isEmpty)) {
                $error = self::runRakit($fieldId, $value, $ruleString);
                if ($error !== null) {
                    $errors[$fieldId] = $error;
                    continue;
                }
            }

            // Structural validation from the field handler.
            $structuralError = $handler::validate($value, $args);
            if ($structuralError !== null) {
                $errors[$fieldId] = $structuralError;
                continue;
            }

            // Repeater subfield validation.
            if ($type === 'repeater' && is_array($value)) {
                $repeaterErrors = self::validateRepeater($value, $args);
                if (!empty($repeaterErrors)) {
                    $errors[$fieldId] = $repeaterErrors;
                    continue;
                }
            }

            $clean[$fieldId] = $value;
        }

        return [
            'errors' => $errors,
            'clean'  => $clean,
        ];
    }

    /**
     * Build the combined Rakit rule string.
     *
     * Reads promoted keys (required, validation) from the field level,
     * and type defaults from the handler.
     */
    private static function buildRuleString(string $handler, array $fieldConfig): string
    {
        $args  = $fieldConfig['args'] ?? [];
        $parts = [];

        if (!empty($fieldConfig['required'])) {
            $parts[] = 'required';
        }

        // Type-specific default rules from the field handler.
        $typeDefaults = $handler::defaultRules($args);
        if ($typeDefaults !== '') {
            foreach (explode('|', $typeDefaults) as $rule) {
                $rule = trim($rule);
                if ($rule !== '' && !in_array($rule, $parts, true)) {
                    $parts[] = $rule;
                }
            }
        }

        // Developer-declared rules from config.
        $declared = $fieldConfig['validation'] ?? '';
        if (is_array($declared)) {
            $declared = implode('|', $declared);
        }

        if ($declared !== '') {
            foreach (explode('|', $declared) as $rule) {
                $rule = trim($rule);
                if ($rule !== '' && !in_array($rule, $parts, true)) {
                    $parts[] = $rule;
                }
            }
        }

        return implode('|', $parts);
    }

    private static function runRakit(string $fieldId, mixed $value, string $ruleString): ?string
    {
        try {
            $validation = self::rakit()->make(
                [$fieldId => $value],
                [$fieldId => $ruleString]
            );
            $validation->validate();

            if ($validation->fails()) {
                return $validation->errors()->first($fieldId);
            }
        } catch (\Throwable $e) {
            return sprintf('Validation error for "%s": %s', $fieldId, $e->getMessage());
        }

        return null;
    }

    private static function validateRepeater(array $rows, array $args): array
    {
        $subfields = $args['subfields'] ?? [];
        $registry  = FieldRegistry::instance();
        $errors    = [];

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowErrors = [];

            foreach ($subfields as $subConfig) {
                $subId      = $subConfig['id'] ?? '';
                $subArgs    = $subConfig['args'] ?? [];
                $subType    = $subConfig['type'] ?? 'text';
                $subHandler = $registry->get($subType);
                $subValue   = $row[$subId] ?? null;

                $isRequired = !empty($subConfig['required']);
                $isEmpty    = $subValue === null || $subValue === '' || $subValue === [];
                $ruleString = self::buildRuleString($subHandler, $subConfig);

                if ($ruleString !== '' && ($isRequired || !$isEmpty)) {
                    $error = self::runRakit($subId, $subValue, $ruleString);
                    if ($error !== null) {
                        $rowErrors[$subId] = $error;
                        continue;
                    }
                }

                $structuralError = $subHandler::validate($subValue, $subArgs);
                if ($structuralError !== null) {
                    $rowErrors[$subId] = $structuralError;
                }
            }

            if (!empty($rowErrors)) {
                $errors[$rowIndex] = $rowErrors;
            }
        }

        return $errors;
    }
}
