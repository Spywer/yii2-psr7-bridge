<?php 
declare(strict_types=1);

namespace yii\Psr7\web;

use yii\Psr7\web\traits\Psr7ResponseTrait;
use Laminas\Diactoros\Stream;

class Response extends \yii\web\Response
{
    use Psr7ResponseTrait;

    public function sendStreamAsFile($handle, $attachmentName, $options = []): \yii\web\Response
    {
        $response = parent::sendStreamAsFile($handle, $attachmentName, $options);
        $this->stream = new Stream($this->stream[0]);
        return $response;
    }

    public function sendFile($filePath, $attachmentName = null, $options = []): \yii\web\Response
    {
        if ($stream = fopen($filePath, 'r')) {
            return $this->sendStreamAsFile($stream, $attachmentName, $options);
        }

        throw new \yii\web\ServerErrorHttpException;
    }
}
