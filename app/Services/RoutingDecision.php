<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Department;
use App\Models\Subcategory;
use App\Models\User;

/** The result of TicketRouter::route(): where a new ticket goes and who (if anyone) already owns it. */
final readonly class RoutingDecision
{
    public function __construct(
        public Department $department,
        public Category $category,
        public ?Subcategory $subcategory,
        public ?User $assignee,
    ) {}

    /** True when nobody owns the ticket yet: it waits in the department's unassigned queue. */
    public function queued(): bool
    {
        return $this->assignee === null;
    }

    /** The columns the ticket is created with. */
    public function attributes(): array
    {
        return [
            'assigned_department_id' => $this->department->id,
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory?->id,
            'assigned_to' => $this->assignee?->id,
        ];
    }

    /** One line for the ticket history. */
    public function note(): string
    {
        return $this->queued()
            ? "Routed to {$this->department->name}. Waiting in the unassigned queue."
            : "Routed to {$this->department->name} and assigned to {$this->assignee->name}.";
    }
}
