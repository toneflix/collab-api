# Toneflix Collab

A music streaming and colaboration platform

### Running Queues

Laravel includes an Artisan command that will start a queue worker and process new jobs as they are pushed onto the queue. You may run the worker using the `queue:work` Artisan command. Note that once the `queue:work` command has started, it will continue to run until it is manually stopped or you close your terminal ([From Laravel Docs](https://laravel.com/docs/9.x/queues#running-the-queue-worker))

The system dispatches tasks and jobs requiring heavy system resource consumtion to a queue thereby limiting the strain on the user waiting for these tasks to complete. You may run the following command to begin processing all queues:

```bash
php artisan queue:work
```

Remember, queue workers are long-lived processes and store the booted application state in memory. As a result, they will not notice changes in your code base after they have been started. So, during your deployment process, be sure to restart your queue workers. In addition, remember that any static state created or modified by your application will not be automatically reset between jobs.

Alternatively, you may run the `queue:listen` command. When using the `queue:listen` command, you don't have to manually restart the worker when you want to reload your updated code or reset the application state; however, this command is significantly less efficient than the queue:work command:

```bash
php artisan queue:listen
```

To keep the `queue:work` process running permanently in the background, you should use a process monitor such as [Supervisor](https://laravel.com/docs/9.x/queues#supervisor-configuration) to ensure that the queue worker does not stop running.

### Running The Scheduler and other task

Where it is not possible to run queues, the task scheduller has also been implemented as an alternative that will automaticaally proccess queues and other schedulled tasks:

```bash
* * * * * installation-path/script.sh
```

This is best run as a cron job.

### Important Dependencies

1. FFmpeg (https://github.com/markus-perl/ffmpeg-build-script OR https://github.com/homebrew-ffmpeg/homebrew-ffmpeg for MAC)
2. libfdk-aac
