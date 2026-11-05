<?php
declare(strict_types=1);

namespace System\Library;

use System\Engine\Registry;

class Validator {
    private array $data;
    private array $rules;
    private array $messages;
    private array $errors = [];
    private Registry $registry;

    public function __construct(Registry $registry, array $data, array $rules, array $messages = []) {
        $this->registry = $registry;
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    public function validate(): bool {
        foreach ($this->rules as $field => $ruleSet) {
            $value = $this->getFieldValue($field);
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);

            foreach ($rules as $rule) {
                // If the rule is 'nullable' and the value is empty, skip all other rules for this field
                if ($rule === 'nullable' && (is_null($value) || $value === '')) {
                    break; 
                }
                if (isset($this->errors[$field])) break;
                $this->applyRule($field, $value, $rule);
            }
        }
        return empty($this->errors);
    }

    private function applyRule(string $field, $value, string $rule): void {
        $param = null;
        if (str_contains($rule, ':')) {
            [$rule, $param] = explode(':', $rule, 2);
        }

        $method = 'validate' . ucfirst($rule);
        if (method_exists($this, $method)) {
            $this->$method($field, $value, $param);
        }
    }

    private function getFieldValue(string $key) {
        $data = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) return null;
            $data = $data[$segment];
        }
        return $data;
    }

    private function addError(string $field, string $rule, string $defaultMessage): void {
        $customKey = "{$field}.{$rule}";
        $this->errors[$field] = $this->messages[$customKey] ?? $defaultMessage;
    }

    public function getErrors(): array {
        return $this->errors;
    }

    /* ---------------------------------
     * HELPER FOR EMPTY CHECKS
     * ---------------------------------*/
    
    // Fixes the PHP `empty(0)` bug
    private function isBlank($value): bool {
        return is_null($value) || (is_string($value) && trim($value) === '') || (is_array($value) && empty($value));
    }

    /* ---------------------------------
     * VALIDATION RULES
     * ---------------------------------*/

    protected function validateRequired($field, $value) {
        if ($this->isBlank($value)) {
            $this->addError($field, 'required', "The $field field is required.");
        }
    }

    protected function validateRequiredIf($field, $value, $param) {
        // Syntax: required_if:other_field,value
        $parts = explode(',', (string)$param, 2);
        $otherField = $parts[0] ?? '';
        $otherValue = $parts[1] ?? '';

        if ($this->getFieldValue($otherField) == $otherValue && $this->isBlank($value)) {
            $this->addError($field, 'required_if', "The $field field is required when $otherField is $otherValue.");
        }
    }

    protected function validateEmail($field, $value) {
        if (!$this->isBlank($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'email', "The $field must be a valid email address.");
        }
    }

    protected function validateMin($field, $value, $param) {
        if ($this->isBlank($value)) return;
        $length = is_array($value) ? count($value) : mb_strlen((string)$value);
        if ($length < (int)$param) {
            $this->addError($field, 'min', "The $field must be at least $param characters.");
        }
    }

    protected function validateMax($field, $value, $param) {
        if ($this->isBlank($value)) return;
        $length = is_array($value) ? count($value) : mb_strlen((string)$value);
        if ($length > (int)$param) {
            $this->addError($field, 'max', "The $field may not be greater than $param characters.");
        }
    }

    protected function validateMatches($field, $value, $param) {
        $otherValue = $this->getFieldValue((string)$param);
        if ($value !== $otherValue) {
            $this->addError($field, 'matches', "The $field does not match the $param field.");
        }
    }

    protected function validateNumeric($field, $value) {
        if (!$this->isBlank($value) && !is_numeric($value)) {
            $this->addError($field, 'numeric', "The $field must be a number.");
        }
    }

    protected function validateAlphaNum($field, $value) {
        if (!$this->isBlank($value) && !ctype_alnum((string)$value)) {
            $this->addError($field, 'alpha_num', "The $field may only contain letters and numbers.");
        }
    }

    protected function validateBoolean($field, $value) {
        $acceptable = [true, false, 1, 0, "1", "0", "true", "false"];
        if (!$this->isBlank($value) && !in_array($value, $acceptable, true)) {
            $this->addError($field, 'boolean', "The $field field must be true or false.");
        }
    }

    protected function validateUrl($field, $value) {
        if (!$this->isBlank($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, 'url', "The $field must be a valid URL.");
        }
    }

    protected function validateRegex($field, $value, $param) {
        if ($this->isBlank($value) || $param === null) return;
        
        // Suppress warnings for invalid regex patterns and just fail validation
        if (@preg_match($param, (string)$value) !== 1) {
            $this->addError($field, 'regex', "The $field format is invalid.");
        }
    }

    protected function validateIp($field, $value) {
        if (!$this->isBlank($value) && !filter_var($value, FILTER_VALIDATE_IP)) {
            $this->addError($field, 'ip', "The $field must be a valid IP address.");
        }
    }

    protected function validateDate($field, $value, $param = null) {
        if ($this->isBlank($value)) return;
        
        // Fix: Fallback to Y-m-d if null is passed
        $format = $param ?: 'Y-m-d';
        $d = \DateTime::createFromFormat($format, (string)$value);
        
        if (!($d && $d->format($format) === $value)) {
            $this->addError($field, 'date', "The $field is not a valid date (format: $format).");
        }
    }

    protected function validateIn($field, $value, $param) {
        if ($this->isBlank($value)) return;
        
        $options = explode(',', (string)$param);
        $values = is_array($value) ? $value : [$value];
        
        foreach ($values as $v) {
            if (!in_array((string)$v, $options, true)) {
                $this->addError($field, 'in', "The selected $field is invalid.");
                break;
            }
        }
    }

    protected function validateUnique($field, $value, $param) {
        if ($this->isBlank($value)) return;
        
        $parts = explode(',', (string)$param);
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $parts[0] ?? '');
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $parts[1] ?? $field);
        $ignoreId = $parts[2] ?? null;
        
        if (!$table || !$column) return;

        $db = $this->registry->get('db');
        $sql = "SELECT COUNT(*) as total FROM #__{$table} WHERE `{$column}` = ?";
        $bind = [$value];

        if ($ignoreId !== null && $ignoreId !== '') {
            $sql .= " AND id != ?";
            $bind[] = (int)$ignoreId;
        }

        $result = $db->query($sql, $bind);
        $total = (int)($result->row['total'] ?? 0);
        
        if ($total > 0) {
            $this->addError($field, 'unique', "This $field is already taken.");
        }
    }
}