<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Every permission key the app checks. Keys are checked server-side on
 * every request; the UI only uses them to hide buttons.
 */
final class Permissions
{
    public const PROJECTS_VIEW = 'projects.view';
    public const PROJECTS_CREATE = 'projects.create';
    public const PROJECTS_EDIT = 'projects.edit';
    public const PROJECTS_COMPLETE = 'projects.complete';
    public const PROJECTS_VIEW_CONFIDENTIAL = 'projects.view_confidential';
    public const COLORS_EDIT = 'colors.edit';
    public const CODES_VIEW = 'codes.view';
    public const CODES_ASSIGN_INTERNAL = 'codes.assign_internal';
    public const CODES_ASSIGN_SELL = 'codes.assign_sell';
    public const CODES_MANUAL_ENTRY = 'codes.manual_entry';
    public const CODES_LINK = 'codes.link';
    public const CODES_VOID = 'codes.void';
    public const CODES_EXPORT = 'codes.export';
    public const REPORTS_VIEW = 'reports.view';
    public const ADMIN_LISTS = 'admin.lists';
    public const ADMIN_PREFIXES = 'admin.prefixes';
    public const ADMIN_SEQUENCES = 'admin.sequences';
    public const ADMIN_USERS = 'admin.users';
    public const ADMIN_NETWORK = 'admin.network';
    public const ADMIN_CMS = 'admin.cms';
    public const ADMIN_SYSTEM = 'admin.system';
    public const ADMIN_IMPORT = 'admin.import';

    /**
     * Plain-language catalogue used by the Users & groups screen.
     * @return array<string,array{label:string,help:string,area:string}>
     */
    public static function catalogue(): array
    {
        return [
            self::PROJECTS_VIEW => ['area' => 'Projects', 'label' => 'See projects', 'help' => 'Open the projects list and read any project that is not confidential.'],
            self::PROJECTS_CREATE => ['area' => 'Projects', 'label' => 'Start new projects', 'help' => 'Create color matches, customer issues, evaluations and R&D projects.'],
            self::PROJECTS_EDIT => ['area' => 'Projects', 'label' => 'Edit projects', 'help' => 'Change project details, add notes, tasks and attachments.'],
            self::PROJECTS_COMPLETE => ['area' => 'Projects', 'label' => 'Complete or cancel projects', 'help' => 'Mark a project finished or cancelled, and reopen it.'],
            self::PROJECTS_VIEW_CONFIDENTIAL => ['area' => 'Projects', 'label' => 'See confidential R&D', 'help' => 'Open R&D projects marked confidential (the lead and administrators always can).'],
            self::COLORS_EDIT => ['area' => 'Projects', 'label' => 'Edit colors and items', 'help' => 'Add, change, reorder and move the colors or items inside a project.'],
            self::CODES_VIEW => ['area' => 'Codes', 'label' => 'Look up codes', 'help' => 'Use code lookup and the code logs. Sales will use this constantly.'],
            self::CODES_ASSIGN_INTERNAL => ['area' => 'Codes', 'label' => 'Assign E numbers', 'help' => 'Reserve the next internal code (E#) for a formula.'],
            self::CODES_ASSIGN_SELL => ['area' => 'Codes', 'label' => 'Assign sell numbers', 'help' => 'Reserve the next sell number under a prefix.'],
            self::CODES_MANUAL_ENTRY => ['area' => 'Codes', 'label' => 'Enter a specific code by hand', 'help' => 'Type an exact code instead of taking the next one. For legacy items and odd cases; every use is logged.'],
            self::CODES_LINK => ['area' => 'Codes', 'label' => 'Link codes together', 'help' => 'Attach an existing sell number to an existing E#, or move a link.'],
            self::CODES_VOID => ['area' => 'Codes', 'label' => 'Void codes', 'help' => 'Mark a mistaken code as void with a reason. The number is never reused.'],
            self::CODES_EXPORT => ['area' => 'Codes', 'label' => 'Export lists', 'help' => 'Download code logs, lookups and reports as CSV or Excel.'],
            self::REPORTS_VIEW => ['area' => 'Reports', 'label' => 'See reports', 'help' => 'Turnaround, aging, codes per month and R&D stage reports.'],
            self::ADMIN_LISTS => ['area' => 'Admin', 'label' => 'Manage lists', 'help' => 'Ink series, coatings, substrates, project types, stages and statuses, customers.'],
            self::ADMIN_PREFIXES => ['area' => 'Admin', 'label' => 'Manage prefixes and suffixes', 'help' => 'Add, edit, retire and un-retire sell number prefixes; manage suffixes.'],
            self::ADMIN_SEQUENCES => ['area' => 'Admin', 'label' => 'Set next numbers', 'help' => 'Move the next E#, project number or prefix counter forward.'],
            self::ADMIN_USERS => ['area' => 'Admin', 'label' => 'Manage users and groups', 'help' => 'Create users, reset passwords, create groups and set what each group may do.'],
            self::ADMIN_NETWORK => ['area' => 'Admin', 'label' => 'Change network settings', 'help' => 'Set the server IP address, gateway and DNS.'],
            self::ADMIN_CMS => ['area' => 'Admin', 'label' => 'Manage the CMS connection', 'help' => 'Configure the read-only Mar-Kov CMS connection and sync customers.'],
            self::ADMIN_SYSTEM => ['area' => 'Admin', 'label' => 'Update, back up and view system status', 'help' => 'Run updates, take backups, see the version and logs.'],
            self::ADMIN_IMPORT => ['area' => 'Admin', 'label' => 'Import legacy codes', 'help' => 'Run the spreadsheet import wizard and resolve import issues.'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::catalogue());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::catalogue());
    }
}
