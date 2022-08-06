# Toggl to Jira

Simple console application for synchronizing time entries from Toggl into JIRA.

## Getting Started

Please follow these instructions to get a local copy and setting up it.

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
$ docker exec -it t2j-app bin/console  sync -vv --start <START_DATE> --end <END_DATE> [--overwrite]
```

Options `start` and `end` accepts datetime strings (absolute or relative).
The `overwrite` option tells the application to remove already existing entries in JIRA.
