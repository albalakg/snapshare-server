# Content Moderation Deployment (EC2)

Image uploads are saved with `pending` status and scanned asynchronously by AWS Rekognition. A queue worker must be running on EC2 for moderation to complete without blocking upload responses.

## Environment

```env
FILESYSTEM_DISK=s3
QUEUE_CONNECTION=database
MODERATION_ENABLED=true
MODERATION_MIN_CONFIDENCE=80
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

Rekognition uses the **EC2 instance IAM role** (default AWS credential chain). Do not add Rekognition-specific access keys to `.env`.

## IAM role

Attach policies that include:

- `rekognition:DetectModerationLabels` (e.g. `AmazonRekognitionFullAccess`)
- `s3:GetObject` on the upload bucket (for `S3Object` image input)

## Database

Run migrations (includes `moderation_labels` on `event_assets`):

```bash
php artisan migrate
```

## Queue worker (Supervisor example)

Install Supervisor, then create `/etc/supervisor/conf.d/snapshare-queue.conf`:

```ini
[program:snapshare-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/snapshare/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/snapshare/storage/logs/queue-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start snapshare-queue:*
```

## Verify

1. Upload an image — API returns `status: 2` (`pending`).
2. Within a few seconds, asset becomes `status: 1` (`active`) and appears on the live screen / gallery.
3. Check `storage/logs/queue-worker.log` and Laravel logs if jobs stay pending.

## Local development

With `QUEUE_CONNECTION=sync`, moderation runs inline on upload (slower response, but no worker needed).

Set `MODERATION_ENABLED=false` to skip Rekognition and mark images `active` immediately.
