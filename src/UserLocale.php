<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;

/**
 * Description of UserLocale
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class UserLocale
{
    /**
     *
     * @var string
     */
    public $timezone;

    /**
     *
     * @var string
     */
    public $locale;

    public function __construct(\CharlotteDunois\Yasmin\Models\User $user)
    {
        $qb   = DatabaseFactory::get()->createQueryBuilder();
        $data = $qb->select("*")->from("locale")->where("user = ?")->setParameter(0, $user->id, "integer")->execute()->fetchAll();
        foreach ($data as $d) {
            $this->timezone = $d['timezone'];
            $this->locale   = $d['locale'];
        }
    }

    public function applyTimezone(Carbon $time): Carbon
    {
        return $time->setTimezone($this->timezone ?? "UTC");
    }

    public function localeSandbox(callable $sandbox): string
    {
        return Carbon::executeWithLocale($this->locale, $sandbox);
    }
}
