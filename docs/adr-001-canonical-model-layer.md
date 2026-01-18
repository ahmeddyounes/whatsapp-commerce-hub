# ADR-001: Canonical Model Layer (Domain vs Entities/ValueObjects)

**Status**: Accepted
**Date**: 2026-01-18
**Deciders**: Development Team
**Scope**: Core architecture, Domain models, Data persistence

---

## Context and Problem Statement

The WhatsApp Commerce Hub codebase currently has **duplicate model definitions** for core business concepts:

- **Cart**, **Customer**, **Conversation** exist in both `includes/Domain/` and `includes/Entities/`
- **Intent** and **Context** exist only in `includes/Domain/Conversation/`
- There is also a `includes/ValueObjects/` directory

This duplication creates several problems:

1. **Unclear ownership**: Which representation is the canonical source of truth?
2. **Maintenance burden**: Changes must be synchronized across duplicate files (currently ~4,000+ lines of duplicated code)
3. **Confusion for developers**: Which model should be used in application services, repositories, or when exposing data via APIs?
4. **Testing complexity**: Which version should tests target?
5. **Risk of divergence**: Duplicate implementations can drift apart, causing runtime bugs

The architecture improvement plan (see `docs/architecture-improvement-plan.md`) identifies this as a Phase 1 priority issue requiring resolution before the codebase can achieve clear architectural boundaries.

---

## Decision Drivers

1. **Clean Architecture principles**: Domain layer should contain pure business logic, free from infrastructure concerns
2. **Dependency Rule**: Dependencies should point inward (Infrastructure → Application → Domain)
3. **Maintainability**: Single source of truth reduces cognitive load and prevents synchronization bugs
4. **Testability**: Pure domain models without WordPress dependencies are easier to unit test
5. **Immutability**: Current readonly-property pattern provides thread-safety and predictable state
6. **Separation of Concerns**: Clear boundaries between business rules and persistence/presentation
7. **WordPress Context**: Must integrate cleanly with WordPress/WooCommerce ecosystem

---

## Considered Options

### Option 1: Domain Models as Canonical (Recommended)

Keep `includes/Domain/` as the single source of truth and remove `includes/Entities/`.

- Domain models remain pure PHP with no WordPress dependencies
- Entities directory is deleted or converted to persistence-layer DTOs if needed
- Repositories return Domain models directly

### Option 2: Entities as Canonical

Keep `includes/Entities/` and remove `includes/Domain/`.

- Treats "Entity" as the canonical business model
- Domain directory would be repurposed or removed
- Less aligned with Clean Architecture terminology

### Option 3: Separate Domain and Persistence Models

Keep both but give them distinct roles:

- `Domain/` = Pure business logic models (no persistence knowledge)
- `Entities/` = Persistence/database layer models (database-aware)
- Add explicit mapping layer between them

### Option 4: Status Quo

Keep both directories with identical code.

- No immediate work required
- Continues current confusion and duplication

---

## Decision Outcome

**Chosen option: Option 1 - Domain Models as Canonical**

### Rationale

1. **Aligns with Clean Architecture**: The `Domain/` namespace clearly communicates architectural intent
2. **Pure Business Logic**: Domain models contain no WordPress/wpdb dependencies, making them easily testable
3. **Industry Standard**: DDD (Domain-Driven Design) terminology is widely understood
4. **Current Implementation Quality**: Domain models already implement:
   - Immutability via readonly properties
   - Factory methods (`fromArray()`, `toArray()`)
   - Rich behavior methods (e.g., `isExpired()`, `isAbandoned()`, `getSegment()`)
   - Validation at construction time
5. **Minimal Disruption**: Repositories already return Domain models in most cases

---

## Implementation Guidelines

### 1. Canonical Model Definitions

**Location**: `includes/Domain/`

| Model | Namespace | File |
|-------|-----------|------|
| Cart | `WhatsAppCommerceHub\Domain\Cart` | `includes/Domain/Cart/Cart.php` |
| Customer | `WhatsAppCommerceHub\Domain\Customer` | `includes/Domain/Customer/Customer.php` |
| Conversation | `WhatsAppCommerceHub\Domain\Conversation` | `includes/Domain/Conversation/Conversation.php` |
| Intent | `WhatsAppCommerceHub\Domain\Conversation` | `includes/Domain/Conversation/Intent.php` |
| Context | `WhatsAppCommerceHub\Domain\Conversation` | `includes/Domain/Conversation/Context.php` |

**Responsibilities**:
- Encapsulate core business rules and invariants
- Provide rich domain behavior (not just data containers)
- Validate data integrity at construction time
- Remain **infrastructure-agnostic** (no WordPress functions, no `wpdb`, no `do_action`)

**Example Pattern**:
```php
namespace WhatsAppCommerceHub\Domain\Cart;

final class Cart {
    // Immutable properties
    public readonly int $id;
    public readonly string $customer_phone;
    public readonly array $items;
    public readonly float $total;
    public readonly string $status;

    // Factory method (validation happens here)
    public static function fromArray(array $row): self {
        // Validate and construct
    }

    // Serialization for persistence
    public function toArray(): array {
        // Convert to array for storage
    }

    // Domain behavior
    public function isEmpty(): bool { ... }
    public function isExpired(): bool { ... }
    public function isAbandoned(): bool { ... }

    // Immutable updates (returns new instance)
    public function withItems(array $items, float $total): self { ... }
    public function withStatus(string $status): self { ... }
}
```

### 2. Persistence Layer Role

**Location**: `includes/Repositories/`

**Responsibilities**:
- **Repositories** handle all database operations (wpdb, SQL, transactions)
- **Return Domain models** to application layer
- **Accept Domain models** for persistence operations
- Perform any necessary **data mapping** (e.g., JSON encoding/decoding, date formatting)
- Handle **concurrency** (row-level locks, optimistic locking)

**Pattern**:
```php
namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Domain\Cart\Cart;

class CartRepository extends AbstractRepository {
    public function find(int $id): ?Cart {
        $row = $this->db->get_row("SELECT * FROM ...");
        return $row ? Cart::fromArray($row) : null;
    }

    public function save(Cart $cart): bool {
        $data = $cart->toArray();
        // wpdb insert/update logic
    }
}
```

### 3. Application Services Role

**Location**: `includes/Application/Services/`

**Responsibilities**:
- Orchestrate **use cases** and business workflows
- Depend **only** on Domain models and Repository contracts (interfaces)
- Coordinate transactions across multiple repositories
- Trigger domain events and side effects

**Pattern**:
```php
namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Domain\Cart\Cart;
use WhatsAppCommerceHub\Contracts\CartRepositoryInterface;

class CartService {
    public function __construct(
        private CartRepositoryInterface $cartRepository
    ) {}

    public function abandonCart(int $cartId): void {
        $cart = $this->cartRepository->find($cartId);
        $updatedCart = $cart->withStatus('abandoned');
        $this->cartRepository->save($updatedCart);
        // Trigger event, etc.
    }
}
```

### 4. DTOs and Value Objects

**When to Create DTOs**:
- **API Responses**: Create dedicated response DTOs in `includes/Presentation/`
- **API Requests**: Create dedicated request DTOs for validation
- **Cross-Boundary Communication**: When external systems need different data shapes

**Value Objects** (`includes/Domain/` or `includes/ValueObjects/`):
- Small, immutable concepts like `PhoneNumber`, `Money`, `EmailAddress`
- Domain primitives that enforce validation (e.g., valid phone format)
- Can be properties of Domain models

**Current Status**:
- Intent and Context are lightweight value objects/services
- No separate DTOs currently exist (Domain models serve dual purpose)
- **Future consideration**: If API response shape diverges significantly from Domain models, create explicit DTOs

### 5. Migration Plan for Entities Directory

**Phase 1** (Immediate):
1. Update all imports to use `WhatsAppCommerceHub\Domain\*` namespace
2. Add class aliases in `includes/Entities/` pointing to Domain models (backward compatibility):
   ```php
   // includes/Entities/Cart.php
   namespace WhatsAppCommerceHub\Entities;

   /**
    * @deprecated Use WhatsAppCommerceHub\Domain\Cart\Cart instead
    */
   class_alias(
       \WhatsAppCommerceHub\Domain\Cart\Cart::class,
       __NAMESPACE__ . '\Cart'
   );
   ```

**Phase 2** (After verification):
1. Search codebase for any remaining `WhatsAppCommerceHub\Entities\` usage
2. Update any external documentation or extension examples
3. Remove the `includes/Entities/` directory entirely

**Phase 3** (Future consideration):
- If persistence-specific models are needed (rare), create them in `includes/Infrastructure/Persistence/Models/`
- Only create if there's a genuine need for different representations (e.g., complex database normalization)

---

## Consequences

### Positive

✅ **Single Source of Truth**: One canonical location for each model
✅ **Reduced Duplication**: Eliminate ~4,000 lines of duplicated code
✅ **Clear Boundaries**: Domain layer is pure business logic, testable in isolation
✅ **Better Testability**: No WordPress dependencies in domain makes unit testing straightforward
✅ **Improved Maintainability**: Changes happen in one place
✅ **Architectural Clarity**: Teams can follow Clean Architecture principles consistently
✅ **IDE Support**: No ambiguity when autocompleting class names

### Negative

⚠️ **Migration Effort**: Need to update imports across codebase (mitigated by class aliases)
⚠️ **Learning Curve**: Developers familiar with "Entity" terminology need to adapt
⚠️ **Potential Refactoring**: Some domain models may have inadvertent WordPress dependencies that need removal

### Neutral

🔵 **No DTOs Yet**: Domain models continue to serve as both business models and data transfer objects
🔵 **Future Evolution**: Can add DTOs later if API/persistence needs diverge significantly

---

## Compliance and Validation

### Static Analysis Rules

Add PHPStan rules to prevent architectural violations:

```neon
# phpstan.neon
parameters:
    paths:
        - includes/Domain
    rules:
        - Acme\ForbidWordPressFunctionsInDomain
```

**Forbidden in Domain Layer**:
- `wpdb` usage
- `get_option()`, `update_option()`
- `do_action()`, `apply_filters()`
- `WP_*` class instantiation
- Direct database queries

### Code Review Checklist

When reviewing Domain model changes:

- [ ] Does the model use only `readonly` properties?
- [ ] Are factory methods (`fromArray()`) validating inputs?
- [ ] Does `toArray()` produce a persistence-ready format?
- [ ] Are there any WordPress function calls? (Should be **none**)
- [ ] Do behavior methods return new instances for "updates"?
- [ ] Are status constants defined and validated?

---

## References

### Related Documents

- `docs/architecture-improvement-plan.md` - Overall architecture strategy (Phase 1)
- `includes/Domain/Cart/Cart.php` - Reference implementation
- `includes/Domain/Customer/Customer.php` - Reference implementation
- `includes/Repositories/CartRepository.php` - Repository pattern example

### Related ADRs

- *None yet* (this is ADR-001)
- Future: ADR on event system integration
- Future: ADR on DTO introduction criteria

### Design Patterns

- **Aggregate Root** (DDD): Cart, Customer, Conversation are aggregate roots
- **Factory Method**: `fromArray()` for construction with validation
- **Immutability**: readonly properties, `with*()` methods return new instances
- **Repository Pattern**: Encapsulates persistence, returns domain models
- **Clean Architecture**: Dependency rule enforced (Domain has no infrastructure dependencies)

### External Resources

- [Clean Architecture by Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Domain-Driven Design by Eric Evans](https://www.domainlanguage.com/ddd/)
- [PHP Immutability with readonly](https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.readonly-properties)

---

## Team Guidelines

### When Adding a New Domain Concept

1. **Create in `includes/Domain/[Context]/`**
   - Example: New concept "Wishlist" → `includes/Domain/Wishlist/Wishlist.php`

2. **Use the standard pattern**:
   - readonly properties
   - `fromArray()` factory method with validation
   - `toArray()` for persistence
   - Behavior methods (business logic)
   - Immutable updates via `with*()` methods

3. **Create corresponding repository** in `includes/Repositories/`
   - Implement `RepositoryInterface` or domain-specific interface
   - Handle all wpdb/SQL concerns
   - Return domain models

4. **Write domain tests** in `tests/Unit/Domain/`
   - No WordPress test harness needed
   - Pure PHP unit tests
   - Fast execution

### When Modifying Existing Models

1. **Check Domain models first**: `includes/Domain/`
2. **Ensure no infrastructure leakage**: No WordPress functions
3. **Update tests**: Keep tests synchronized with changes
4. **Maintain immutability**: Don't add mutable setters

### Extension Points for Plugin Authors

External plugins should:
- **Use Domain models** for type safety and validation
- **Access via repositories** or application services (not direct DB access)
- **Listen to events** for extensibility (not modify domain models directly)
- **Create own domain models** for custom concepts

---

## Acceptance Criteria

This ADR is considered successfully implemented when:

- [x] ADR document is written and approved
- [ ] All code imports use `WhatsAppCommerceHub\Domain\*` namespace
- [ ] `includes/Entities/` contains only class aliases (backward compatibility) or is removed
- [ ] PHPStan rules prevent WordPress functions in Domain layer
- [ ] Documentation (`docs/extending.md`) references Domain models
- [ ] Team onboarding materials explain the canonical model decision

---

## Notes

This decision is foundational for the architecture improvement plan Phase 1. It establishes clear boundaries that enable:

- **Phase 2**: Feature modularity (modules depend on clear domain contracts)
- **Phase 3**: Container improvements (DI with well-defined dependencies)
- **Phase 5**: Presentation layer cleanup (controllers use domain models via application services)
- **Phase 6**: Testing improvements (domain models are easily testable)

**Review Date**: 2026-07-18 (6 months) - Evaluate if DTO layer is needed based on API evolution
