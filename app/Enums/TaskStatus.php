<?php

namespace App\Enums;

enum TaskStatus: string
{
    use EnumHelpers;

    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
}
