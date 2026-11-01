<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

/** Phase 18: every ticket status has its OWN badge colour, and that colour exists in the CSS. */
class TicketStatusBadgeTest extends TestCase
{
    public function test_every_status_has_a_different_badge_class(): void
    {
        $classes = array_map(fn (TicketStatus $s) => $s->badgeClass(), TicketStatus::cases());

        $this->assertSame($classes, array_values(array_unique($classes)), 'two statuses share one colour');
        foreach ($classes as $class) {
            $this->assertStringStartsWith('stx-', $class);
        }
    }

    public function test_badge_class_names_use_dashes(): void
    {
        $this->assertSame('stx-in-progress', TicketStatus::InProgress->badgeClass());
        $this->assertSame('stx-pending-authorization', TicketStatus::PendingAuthorization->badgeClass());
        $this->assertSame('stx-submitted', TicketStatus::Submitted->badgeClass());
    }

    public function test_every_badge_class_is_defined_in_phase6_css(): void
    {
        $css = file_get_contents(__DIR__.'/../../public/css/phase6.css');

        foreach (TicketStatus::cases() as $status) {
            $this->assertStringContainsString('.badge.'.$status->badgeClass().'{', $css, $status->value.' has no colour in phase6.css');
        }
    }
}
