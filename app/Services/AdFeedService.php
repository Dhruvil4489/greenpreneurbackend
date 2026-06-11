<?php

namespace App\Services;

use App\Http\Resources\V1\AdResource;
use App\Models\Ad;
use Illuminate\Support\Collection;

class AdFeedService
{
    private const MIN_AUTO_AD_GAP = 6;

    private const MAX_AUTO_AD_GAP = 12;

    public function timelineAds(?int $limit = null): Collection
    {
        $now = now();

        $query = Ad::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($builder) use ($now) {
                $builder->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('created_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function mergeTimelineFeed(Collection $posts, Collection $ads, int $page = 1): Collection
    {
        if ($ads->isEmpty()) {
            return $posts->values();
        }

        $postItems = $posts->values()->all();

        $automaticAds = $ads
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();

        return $this->distributeAutomaticAds($postItems, $automaticAds, $page);
    }

    private function distributeAutomaticAds(array $items, array $automaticAds, int $page): Collection
    {
        if (empty($automaticAds)) {
            return collect($items)->values();
        }

        $result = [];
        $postCountSinceLastAuto = 0;
        $autoAdIndex = 0;
        $seed = $this->buildSeed($automaticAds, $page, count($items));
        $nextGap = $this->nextGap($seed);

        foreach ($items as $item) {
            $result[] = $item;

            if (($item['type'] ?? null) !== 'post') {
                continue;
            }

            $postCountSinceLastAuto++;

            if ($postCountSinceLastAuto >= $nextGap && isset($automaticAds[$autoAdIndex])) {
                $result[] = $this->transformAd($automaticAds[$autoAdIndex]);
                $autoAdIndex++;
                $postCountSinceLastAuto = 0;
                $nextGap = $this->nextGap($seed);
            }
        }

        return collect($result)->values();
    }

    private function buildSeed(array $automaticAds, int $page, int $itemCount): int
    {
        $adIds = collect($automaticAds)->pluck('id')->implode('|');
        $seedBase = sprintf('page:%d|items:%d|ads:%s', $page, $itemCount, $adIds);

        return (int) sprintf('%u', crc32($seedBase));
    }

    private function nextGap(int &$seed): int
    {
        $seed = (int) (($seed * 1103515245 + 12345) & 0x7fffffff);
        $range = self::MAX_AUTO_AD_GAP - self::MIN_AUTO_AD_GAP + 1;

        return self::MIN_AUTO_AD_GAP + ($seed % $range);
    }

    private function transformAd(Ad $ad): array
    {
        return AdResource::make($ad)->resolve();
    }
}
