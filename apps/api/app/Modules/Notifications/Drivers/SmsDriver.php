<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

/**
 * The slot for SMS (plan section 10.1: "SMS has an interface slot and no driver"). No gateway has been chosen, so nothing
 * implements this and the registry never builds it: a gateway is a decision for Ramin. When one is picked, a class implements
 * this interface and the registry maps the sms channel to it, with a flag and its keys like the other drivers.
 */
interface SmsDriver extends Driver {}
