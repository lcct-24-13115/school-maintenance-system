<?php

namespace App\Observers;

use App\Models\Faq;
use App\Support\FaqCache;

/**
 * Help & FAQ Phase 5C: any change to an entry (add, edit, hide / publish, delete) clears the cached lists, so the
 * Help page shows the change at once. Registered in AppServiceProvider.
 * (Writes that skip model events call FaqCache::flush() themselves: FaqAdminController::move() and faq:seed.)
 */
class FaqObserver
{
    public function saved(Faq $faq): void
    {
        FaqCache::flush();
    }

    public function deleted(Faq $faq): void
    {
        FaqCache::flush();
    }
}