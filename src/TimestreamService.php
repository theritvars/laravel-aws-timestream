<?php

namespace Ringierimu\LaravelAwsTimestream;

use Aws\Result;
use Aws\TimestreamQuery\Exception\TimestreamQueryException;
use Aws\TimestreamQuery\TimestreamQueryClient;
use Aws\TimestreamWrite\Exception\TimestreamWriteException;
use Aws\TimestreamWrite\TimestreamWriteClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ringierimu\LaravelAwsTimestream\Dto\TimestreamReaderDto;
use Ringierimu\LaravelAwsTimestream\Dto\TimestreamWriterDto;
use Ringierimu\LaravelAwsTimestream\Exception\FailTimestreamQueryException;
use Ringierimu\LaravelAwsTimestream\Exception\FailTimestreamWriterException;
use Ringierimu\LaravelAwsTimestream\Exception\UnknownTimestreamDataTypeException;

class TimestreamService
{
    public TimestreamQueryClient $reader;

    public TimestreamWriteClient $writer;

    public function __construct(TimestreamManager $manager)
    {
        $this->reader = $manager->getReader();
        $this->writer = $manager->getWriter();
    }

    public function batchWrite(TimestreamWriterDto $timestreamWriter): void
    {
        $this->ingest($timestreamWriter->toArray());
    }

    public function write(TimestreamWriterDto $timestreamReader): void
    {
        $this->ingest($timestreamReader->toArray());
    }

    private function ingest(array $payload): void
    {
        try {
            $result = $this->writer->writeRecords($payload);
        } catch (TimestreamWriteException $e) {
            $records = $payload['Records'];
            if ($e->getAwsErrorCode() === 'RejectedRecordsException') {
                $records = collect($e->get('RejectedRecords'))
                    ->map(function ($data) use ($records) {
                        return [
                            'RecordIndex' => $data['RecordIndex'],
                            'Record' => $records[$data['RecordIndex']],
                            'Reason' => $data['Reason'],
                        ];
                    })->all();
            }

            throw new FailTimestreamWriterException($e, $records);
        }

        if (($status = Arr::get($result->get('@metadata') ?? [], 'statusCode')) != 200) {
            Log::debug('Failed To insert Timestream', $payload);

            throw new FailTimestreamWriterException($status);
        }
    }

    public function query(TimestreamReaderDto $timestreamReader): Collection
    {
        return $this->runQuery($timestreamReader);
    }

    private function runQuery(TimestreamReaderDto $timestreamReader, string $nextToken = null): Collection
    {
        $params = $timestreamReader->toArray();
        if ($nextToken) {
            $params['NextToken'] = $nextToken;
        }

        try {
            if ($this->shouldDebugQuery()) {
                Log::debug('=== Timestream Query ===', $params);
            }

            $result = $this->reader->query($params);
            if ($token = $result->get('NextToken')) {
                return $this->runQuery($timestreamReader, $token);
            }
        } catch (TimestreamQueryException $e) {
            throw new FailTimestreamQueryException($e, $params);
        }

        return $this->parseQueryResult($result);
    }

    private function parseQueryResult(Result $result): Collection
    {
        if ($this->shouldDebugQuery()) {
            Log::debug('=== Query status === ', $result->get('QueryStatus'));
        }

        $columnInfo = $result->get('ColumnInfo');

        if ($this->shouldDebugQuery()) {
            Log::debug('=== Query Metadata === ', $columnInfo);
        }

        return collect($result->get('Rows'))
            ->map(fn ($row) => $this->parseRow($row, $columnInfo));
    }

    private function parseRow(array $row, array $columnInfo): array
    {
        $rowFormatted = [];
        foreach ($row['Data'] as $key => $value) {
            $formattedKey = Str::beforeLast(Arr::get($columnInfo, "{$key}.Name"), '::');
            if (Arr::has($rowFormatted, $formattedKey) == false
                || (
                    Arr::has($rowFormatted, $formattedKey) == true
                    && Arr::get($rowFormatted, $formattedKey) == null
                )
            ) {
                $rowFormatted[$formattedKey] = $this->dataType(
                    Arr::get(
                        $columnInfo,
                        "{$key}.Type.ScalarType"
                    ),
                    Arr::get($value, 'ScalarValue', null)
                );
            }
        }

        return $rowFormatted;
    }

    protected function dataType(string $type, $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $return = match ($type) {
            'BIGINT' => (int) $value,
            'VARCHAR' => (string) $value,
            'DOUBLE' => (float) $value,
            'TIMESTAMP' => Carbon::createFromFormat('Y-m-d H:i:s.u000', $value),
            default => throw new UnknownTimestreamDataTypeException('Unkown Data Type From TimeStream: ' . $type),
        };

        return $return;
    }

    private function shouldDebugQuery(): bool
    {
        return config('timestream.debug_query', false);
    }
}