<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/** What happened to the object. `Irreversible` is recorded for the history but can never be undone. */
enum ChangeAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Trash = 'trash';
    case Irreversible = 'irreversible';
}
