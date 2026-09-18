<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

/**
 * JSON 文件加载器(design/23 §2.2 形态 B):spec.file 相对场景文件目录或绝对路径。
 *
 * 文件内容必须是"记录数组"(array of objects);非法 JSON / 非数组 / 记录非 object → 422。
 */
final class JsonFileLoader implements DatasetLoaderInterface
{
    public function __construct(string $baseDir = '.')
    {
        $this->baseDir = $baseDir;
    }

    /** @var string 相对路径的基准目录(场景文件所在目录) */
    private $baseDir;

    /**
     * @param array<string, mixed> $spec {file: string}
     * @return array<int, array<string, mixed>>
     */
    public function load(array $spec): array
    {
        $file = (string) ($spec['file'] ?? '');
        if ($file === '') {
            throw new \Ws\Http\Automated\AutomatedException('dataset file loader requires "file" option', 422);
        }

        $path = $file[0] === DIRECTORY_SEPARATOR ? $file : $this->baseDir . DIRECTORY_SEPARATOR . $file;
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw new \Ws\Http\Automated\AutomatedException(sprintf('cannot read dataset file "%s"', $path), 422);
        }

        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            throw new \Ws\Http\Automated\AutomatedException(sprintf('dataset file "%s" is not a JSON array', $path), 422);
        }

        foreach ($data as $i => $record) {
            if (!\is_array($record)) {
                throw new \Ws\Http\Automated\AutomatedException(sprintf('dataset file "%s" record #%d is not an object', $path, $i), 422);
            }
        }

        return $data;
    }
}
