<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

final class PaginatedListKeyboard
{
    use BuildsInlineKeyboard;

    public const int PER_PAGE = 5;

    private const int FILTERS_PER_ROW = 3;

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @param  list<array{id: string, label: string}>  $filters
     * @param  array{text: string, callback_data: string, style?: string}|null  $extraFooter
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function markup(
        array $items,
        array $filters,
        string $activeFilter,
        int $page,
        string $listCallbackPrefix,
        string $backCallback,
        ?array $extraFooter,
    ): array {
        $itemCount = count($items);
        $safePage = self::clampedPage($page, $itemCount);
        $rows = [];

        if (! empty($filters)) {
            $filterRow = [];

            foreach ($filters as $filter) {
                if (count($filterRow) === self::FILTERS_PER_ROW) {
                    $rows[] = $filterRow;
                    $filterRow = [];
                }

                $filterData = $listCallbackPrefix . ':list:' . $filter['id'] . ':1';

                if ($filter['id'] === $activeFilter) {
                    $filterRow[] = self::cbPrimary($filter['label'], $filterData);
                } else {
                    $filterRow[] = self::cb($filter['label'], $filterData);
                }
            }

            $rows[] = $filterRow;
        }

        if ($itemCount > 0) {
            $offset = ($safePage - 1) * self::PER_PAGE;
            $slice = array_slice($items, $offset, self::PER_PAGE);

            foreach ($slice as $item) {
                $rows[] = [self::cb($item['text'], $item['callback_data'])];
            }
        }

        $prevPage = $safePage - 1;
        $nextPage = $safePage + 1;

        $rows[] = [
            self::cb(__('telegram.btn.page_prev'), $listCallbackPrefix . ':list:' . $activeFilter . ':' . $prevPage),
            self::cb(__('telegram.btn.page_next'), $listCallbackPrefix . ':list:' . $activeFilter . ':' . $nextPage),
        ];

        if ($extraFooter !== null) {
            if (array_key_exists('style', $extraFooter)) {
                $rows[] = [[
                    'text' => $extraFooter['text'],
                    'callback_data' => $extraFooter['callback_data'],
                    'style' => $extraFooter['style'],
                ]];
            } else {
                $rows[] = [self::cb($extraFooter['text'], $extraFooter['callback_data'])];
            }
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), $backCallback)];

        return self::inline($rows);
    }

    public static function clampedPage(int $page, int $itemCount): int
    {
        if ($itemCount <= 0) {
            return 1;
        }

        $max = self::pageCount($itemCount);

        if ($page < 1) {
            return 1;
        }

        if ($page > $max) {
            return $max;
        }

        return $page;
    }

    public static function isOutOfRange(int $page, int $itemCount): bool
    {
        if ($itemCount <= 0) {
            return $page !== 1;
        }

        return $page < 1 || $page > self::pageCount($itemCount);
    }

    public static function pageCount(int $itemCount): int
    {
        if ($itemCount <= 0) {
            return 1;
        }

        return (int) ceil($itemCount / self::PER_PAGE);
    }

    /**
     * @return array{filter: string, page: int}|null
     */
    public static function listState(string $data, string $listCallbackPrefix): ?array
    {
        $pattern = '/^' . preg_quote($listCallbackPrefix, '/') . ':list:([A-Za-z0-9_]+):(-?\d+)$/';

        if (preg_match($pattern, $data, $m) !== 1) {
            return null;
        }

        return [
            'filter' => $m[1],
            'page' => (int) $m[2],
        ];
    }
}
