<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Sync;

defined('ABSPATH') || exit;

use Throwable;

final class FriendlyMessage
{
    public function for(Throwable $e): string
    {
        return $this->translate($e->getMessage());
    }

    public function translate(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'expired') || str_contains($lower, 'reconnect') || str_contains($lower, 'unauthenticated')) {
            return __('Your NoTrouble connection has expired. Open the Pack & Go page and connect again, then retry.', 'pack-and-go');
        }

        if (str_contains($lower, 'could not reach') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'cURL') || str_contains($lower, 'resolve host')) {
            return __('We could not reach NoTrouble. Check this server\'s internet connection and try again in a moment.', 'pack-and-go');
        }

        if (str_contains($lower, 'permission') || str_contains($lower, 'not allowed') || str_contains($lower, 'forbidden') || str_contains($lower, 'scope')) {
            return __('NoTrouble would not allow this change. Make sure you connected the profile you want to import into, and that your account can edit it.', 'pack-and-go');
        }

        if (str_contains($lower, 'plan') || str_contains($lower, 'upgrade') || str_contains($lower, 'payment required')) {
            return __('Your NoTrouble plan does not include this. You may need to upgrade your plan to finish importing.', 'pack-and-go');
        }

        if (str_contains($lower, 'private') || str_contains($lower, 'reserved') || str_contains($lower, 'loopback')) {
            return __('An image could not be imported because its address is not publicly reachable. Images must be on a public URL.', 'pack-and-go');
        }

        if (str_contains($lower, 'too large') || str_contains($lower, 'too big') || str_contains($lower, 'storage')) {
            return __('A file was too large for your NoTrouble plan\'s limit and was skipped.', 'pack-and-go');
        }

        if (str_contains($lower, 'slug') || str_contains($lower, 'already been taken') || str_contains($lower, 'unique')) {
            return __('An item with this name already exists in NoTrouble, so it was skipped.', 'pack-and-go');
        }

        if (str_contains($lower, 'required') || str_contains($lower, 'must be') || str_contains($lower, 'invalid')) {
            return sprintf(
                /* translators: %s: the underlying validation message */
                __('NoTrouble could not accept this item: %s', 'pack-and-go'),
                $message,
            );
        }

        if ($message !== '' && mb_strlen($message) <= 160) {
            return $message;
        }

        return __('Something went wrong importing this item. It was skipped — you can try again later.', 'pack-and-go');
    }
}
