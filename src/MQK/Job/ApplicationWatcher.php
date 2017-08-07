<?php
namespace MQK\Job;


interface ApplicationWatcher
{
    public function id();
    public function setId($id);
    public function complete(ParallelWatcher $parallelWatcher);
}