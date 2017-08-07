<?php
namespace MQK\Job;

class ParallelJobTest extends TestCase
{
    public function testParallel()
    {
        $appWacher = null;

        $parallelWacher = null;

        $job1 = new ParallelJob($parallelWacher);
        $job2 = new ParallelJob($parallelWacher);
        $job3 = new ParallelJob($parallelWacher);
        $job4 = new ParallelJob($parallelWacher);
    }
}