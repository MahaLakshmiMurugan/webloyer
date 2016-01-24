<?php

namespace App\Jobs;

use App\Jobs\Job;
use App\Repositories\Deployment\DeploymentInterface;
use App\Repositories\Project\ProjectInterface;
use App\Repositories\Server\ServerInterface;
use App\Services\Notification\NotifierInterface;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

use Symfony\Component\Process\ProcessBuilder;

class Deploy extends Job implements SelfHandling, ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $deployment;

    protected $executable;

    /**
     * Create a new job instance.
     *
     * @param \Illuminate\Database\Eloquent\Model $deployment
     * @return void
     */
    public function __construct(Model $deployment)
    {
        $this->deployment = $deployment;
        $this->executable = base_path('vendor/bin/dep');
    }

    /**
     * Execute the job.
     *
     * @param \App\Repositories\Deployment\DeployCommanderInterface $deploymentRepository
     * @param \App\Repositories\Project\ProjectInterface            $projectRepository
     * @param \App\Repositories\Server\ServerInterface              $serverRepository
     * @param \Symfony\Component\Process\ProcessBuilder             $processBuilder
     * @param \App\Services\Notification\NotifierInterface          $notifier
     * @return void
     */
    public function handle(DeploymentInterface $deploymentRepository, ProjectInterface $projectRepository, ServerInterface $serverRepository, ProcessBuilder $processBuilder, NotifierInterface $notifier)
    {
        $deployment = $this->deployment;
        $project    = $projectRepository->byId($deployment->project_id);
        $server     = $serverRepository->byId($project->server_id);

        $app = app();

        // Create a server list file
        $serverListFileBuilder = $app->make('App\Services\Deployment\DeployerServerListFileBuilder', [$server]);
        $serverListFile = $app->make('App\Services\Deployment\DeployerFileDirector', [$serverListFileBuilder])->construct();

        // Create recipe files
        foreach ($project->recipes as $i => $recipe) {
            $recipeFileBuilders[] = $app->make('App\Services\Deployment\DeployerRecipeFileBuilder', [$recipe]);
            $recipeFiles[] = $app->make('App\Services\Deployment\DeployerFileDirector', [$recipeFileBuilders[$i]])->construct();
        }

        // Create a deployment file
        $deploymentFileBuilder = $app->make('App\Services\Deployment\DeployerDeploymentFileBuilder', [$project, $serverListFile, $recipeFiles]);
        $deploymentFile = $app->make('App\Services\Deployment\DeployerFileDirector', [$deploymentFileBuilder])->construct();

        // Create a command
        $processBuilder
            ->add($this->executable)
            ->add("-f={$deploymentFile->getFullPath()}")
            ->add('--ansi')
            ->add('-n')
            ->add('-vv')
            ->add('deploy')
            ->add($project->stage);

        // Run the command
        $tmp['id']      = $deployment->id;
        $tmp['message'] = '';

        $process = $processBuilder->getProcess();
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$tmp, $deploymentRepository) {
            $tmp['message'] .= $buffer;

            $deploymentRepository->update($tmp);
        });

        // Store the result
        if ($process->isSuccessful()) {
            $message = $process->getOutput();
        } else {
            $message = $process->getErrorOutput();
        }

        $data['id']      = $deployment->id;
        $data['message'] = $message;
        $data['status']  = $process->getExitCode();

        $deploymentRepository->update($data);

        // Notify
        if (isset($project->email_notification_recipient)) {
            $deployment = $deploymentRepository->byId($deployment->id);

            if ($process->isSuccessful()) {
                $status = 'success';
            } else {
                $status = 'failure';
            }
            $subject = "Deployment of {$project->name} #{$deployment->number} finished: {$status}";

            $message = view('emails.notification')
                ->with('project', $project)
                ->with('deployment', $deployment)
                ->render();

            $notifier->to($project->email_notification_recipient)->notify($subject, $message);
        }
    }
}
