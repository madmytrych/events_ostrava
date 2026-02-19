<?php

namespace App\Services\Bot;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class EventQueryService
{
    public function getTodayEvents(?int $ageMin, ?int $ageMax, int $limit = 10): Collection
    {
        $now = Carbon::now('Europe/Prague');
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        return $this->baseQuery($ageMin, $ageMax)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    public function getWeekendEvents(?int $ageMin, ?int $ageMax, int $limit = 10): Collection
    {
        $now = Carbon::now('Europe/Prague');
        $start = $now->copy()->next(Carbon::SATURDAY)->startOfDay();
        $end = $start->copy()->next(Carbon::SUNDAY)->endOfDay();

        if ($now->isSaturday()) {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->next(Carbon::SUNDAY)->endOfDay();
        } elseif ($now->isSunday()) {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        }

        return $this->baseQuery($ageMin, $ageMax)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    public function getTomorrowEvents(?int $ageMin, ?int $ageMax, int $limit = 10): Collection
    {
        $now = Carbon::now('Europe/Prague');
        $start = $now->copy()->addDay()->startOfDay();
        $end = $start->copy()->endOfDay();

        return $this->baseQuery($ageMin, $ageMax)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    public function getWeekEvents(?int $ageMin, ?int $ageMax, int $limit = 10): Collection
    {
        $now = Carbon::now('Europe/Prague');
        $start = $now->copy()->startOfWeek();
        $end = $now->copy()->endOfWeek();

        return $this->baseQuery($ageMin, $ageMax)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    public function getNewEventsSince(Carbon $since, ?int $ageMin, ?int $ageMax, int $limit = 20): Collection
    {
        return $this->baseQuery($ageMin, $ageMax)
            ->where('created_at', '>=', $since)
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    private function baseQuery(?int $ageMin, ?int $ageMax)
    {
        $query = Event::query()
            ->where('status', '!=', 'rejected')
            ->where('is_active', true)
            ->whereNull('duplicate_of_event_id');

        if ($ageMin !== null && $ageMax !== null) {
            $query->where(function ($q) use ($ageMax) {
                $q->whereNull('age_min')->orWhere('age_min', '<=', $ageMax);
            })->where(function ($q) use ($ageMin) {
                $q->whereNull('age_max')->orWhere('age_max', '>=', $ageMin);
            });
        }

        return $query;
    }
}
