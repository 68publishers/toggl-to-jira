<h1 align="center">Toggl to Jira</h1>

<p align="center">Simple console application for synchronizing time entries from Toggl into JIRA.</p>

## Getting Started

Please follow these instructions to get a local copy and set it up.

### Environment

- git
- docker & docker-compose

### Installation

1. Clone the repository and install the application

```sh
$ git clone https://github.com/68publishers/toggl-to-jira.git
$ cd toggl-to-jira
$ ./installer
```

2. Open the `.env` file and set up your credentials

| Variable | Type    | Description |
| ------ |---------|-------------|
| APP_DEBUG | Boolean | Enables debug mode for Tracy |
| TOGGL_API_TOKEN | String  | Auth token for your Toggle account |
| JIRA_USERNAME | String | Username (email) of your JIRA account |
| JIRA_API_TOKEN | String | Auth token for your JIRA account |
| JIRA_WEBSITE_URL | String | URL of your JIRA website |

## Synchronization

Synchronization is started with the following command

```sh
$ docker exec -it t2j-app bin/console sync --start <START_DATE> --end <END_DATE> [--group-by-day] [--rounding <ROUNDING>] [--issue <ISSUE_CODE>] [--dump-only] [--no-interaction]
```

Options `start` and `end` accepts datetime strings (absolute or relative).

Option `--group-by-day` tells the application to group all daily entries into one.

Option `--rounding` accepts an integer value in the range [2-60]. All entries will be rounded to up the minutes if the option is used.

Option `--issue` accepts an issue code to be synchronized. Multiple values can be declared. If the option is omitted then all entries are synchronized.

Option `--dump-only` displays only change set and summary tables, but does not synchronize anything.

If you schedule the command into CRONTAB, use the `--no-interaction` option (or the shortcut `-n`) so that the console does not ask if you want to synchronize the entries.

## Description format

Descriptions of time entries in Toggl must follow the following pattern

```
<IssueCode> [<IssueName>] [<OptionalComment>]
```

For example, if the issue in JIRA has code `PROJ-123` and the name of the issue is `UX improvements` then the following examples are acceptable:

- `PROJ-123` - the entry is imported with an empty comment
- `PROJ-123 UX improvements` - the entry is imported with an empty comment
- `PROJ-123 UX improvements Fixed footer on small devices` - the entry is imported with a comment `Fixed footer on small devices`
- `PROJ-123 Fixed footer on small devices` - the entry is imported with a comment `Fixed footer on small devices`

## License

The package is distributed under the MIT License. See [LICENSE](LICENSE.md) for more information.
