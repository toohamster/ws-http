<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Laravel\Commands;

use Illuminate\Console\Command;
use SnowmanNunu\Ai\Exceptions\AiException;

class AiTestCommand extends Command
{
    protected $signature = 'ai:test
                            {--driver= : 驱动名称，默认读取 ai.default}
                            {--prompt= : 测试提示词，默认“你好，请用一句话介绍自己”}
                            {--stream : 使用流式输出}';

    protected $description = '测试 AI 驱动连通性';

    public function handle(): int
    {
        $driver = $this->option('driver') ?: config('ai.default');
        $prompt = $this->option('prompt') ?: '你好，请用一句话介绍自己';
        $useStream = (bool) $this->option('stream');

        if (empty($driver)) {
            $this->error('未配置默认 AI 驱动，请通过 --driver 指定或在 config/ai.php 中设置 default');

            return 1;
        }

        $this->info("正在使用驱动 [{$driver}] 发送测试请求…");

        try {
            $ai = app('ai')->driver($driver);

            if ($useStream) {
                foreach ($ai->stream([['role' => 'user', 'content' => $prompt]]) as $chunk) {
                    if ($chunk->reasoningContent !== null) {
                        $this->output->write('<comment>'.$chunk->reasoningContent.'</comment>');
                    }
                    $this->output->write($chunk->content);
                }
                $this->output->writeln('');

                return 0;
            }

            $response = $ai->chat([
                ['role' => 'user', 'content' => $prompt],
            ]);

            $this->info('模型：'.$response->model);
            $this->info('回复：'.$response->content);
            $this->info('Token：'.$response->usage->totalTokens);
            if ($response->finishReason) {
                $this->info('结束原因：'.$response->finishReason);
            }

            return 0;
        } catch (AiException $e) {
            $this->error('AI 调用失败：'.$e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return 1;
        }
    }
}
