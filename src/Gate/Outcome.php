<?php

declare(strict_types=1);

namespace Belayline\Gate;

enum Outcome: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case ApprovalRequired = 'approval_required';
}
