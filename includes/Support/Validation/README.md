# Validation Support

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for validation utilities and rules, which will provide:
- Reusable validation rules for forms, API inputs, and domain objects
- Common validators (email, phone, URL, etc.)
- Custom validation rule builders
- Validation error formatting and i18n

## Migration Plan

This is part of the support/utility consolidation effort. The validation module will include:

- `ValidatorInterface.php` - Base validator contract
- `Rule.php` - Abstract base for validation rules
- `Rules/` - Collection of common validation rules (Email, Phone, Required, etc.)
- `ValidationResult.php` - Structured validation result with error messages
- `ValidationException.php` - Exception for validation failures

## Current State

Validation logic is currently scattered across:
- Controllers (REST input validation)
- Services (business rule validation)
- Forms (admin UI validation)

This directory will centralize validation logic to promote reuse and consistency.

## Example Usage (Planned)

```php
use WhatsAppCommerceHub\Support\Validation\Validator;
use WhatsAppCommerceHub\Support\Validation\Rules;

$validator = new Validator([
    'email' => [new Rules\Required(), new Rules\Email()],
    'phone' => [new Rules\Required(), new Rules\Phone()],
]);

$result = $validator->validate($data);
if (!$result->isValid()) {
    throw new ValidationException($result->getErrors());
}
```

## References

- Current validation: Scattered across controllers and services
- Similar patterns: Input sanitization in `includes/Infrastructure/Security/`
