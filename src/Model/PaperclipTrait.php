<?php

namespace Czim\Paperclip\Model;

use Czim\FileHandling\Contracts\Storage\StorableFileFactoryInterface;
use Czim\Paperclip\Attachment\Attachment;
use Czim\Paperclip\Contracts\AttachableInterface;
use Czim\Paperclip\Contracts\AttachmentFactoryInterface;
use Czim\Paperclip\Contracts\AttachmentInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin AttachableInterface
 */
trait PaperclipTrait
{
    /**
     * All of the model's current attached files.
     *
     * @var AttachmentInterface[]
     */
    protected $attachedFiles = [];

    /**
     * Whether an attachment has been updated and requires further processing.
     *
     * @var bool
     */
    protected $attachedUpdated = false;


    /**
     * Returns the list of attached files.
     *
     * @return AttachmentInterface[]
     */
    public function getAttachedFiles()
    {
        return $this->attachedFiles;
    }

    /**
     * Add a new file attachment type to the list of available attachments.
     *
     * @param string $name
     * @param array  $options
     */
    public function hasAttachedFile($name, array $options = [])
    {
        /** @var AttachmentFactoryInterface $factory */
        $factory = app(AttachmentFactoryInterface::class);

        $attachment = $factory->create($this, $name, $options);

        $this->attachedFiles[ $name ] = $attachment;
    }

    public static function bootPaperclipTrait(): void
    {
        static::saved(function ($model) {
            /** @var Model|AttachableInterface $model */
            if ($model->attachedUpdated) {
                // Unmark attachment being updated, so the processing isn't fired twice
                // when the attachedfile performs a model update for the variants column.
                $model->attachedUpdated = false;

                foreach ($model->getAttachedFiles() as $attachedFile) {
                    $attachedFile->afterSave($model);
                }

                $model->mergeFileAttributes();
            }
        });

        static::saving(function ($model) {
            /** @var Model|AttachableInterface $model */
            $model->removeFileAttributes();
        });

        static::updating(function ($model) {
            /** @var Model|AttachableInterface $model */
            $model->removeFileAttributes();
        });

        static::retrieved(function ($model) {
            /** @var Model|AttachableInterface $model */
            $model->mergeFileAttributes();
        });

        static::deleting(function ($model) {
            /** @var Model|AttachableInterface $model */
            foreach ($model->getAttachedFiles() as $attachedFile) {
                $attachedFile->beforeDelete($model);
            }
        });

        static::deleted(function ($model) {
            /** @var Model|AttachableInterface $model */
            foreach ($model->getAttachedFiles() as $attachedFile) {
                $attachedFile->afterDelete($model);
            }
        });
    }

    /**
     * Handle the dynamic retrieval of attachment objects.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->attachedFiles) && $this->areAttachmentAttributesLoaded($key)) {
            return $this->attachedFiles[ $key ];
        }

        return parent::getAttribute($key);
    }

    protected function areAttachmentAttributesLoaded(string $attachment): bool
    {
        if (! array_key_exists($attachment, $this->attachedFiles)) {
            return false;
        }

        return array_key_exists("{$attachment}_file_name", $this->attributes);
    }

    /**
     * Handles the setting of attachment objects.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function setAttribute($key, $value)
    {
        if (array_key_exists($key, $this->attachedFiles)) {

            if ($value) {
                $attachedFile = $this->attachedFiles[ $key ];

                if ($value === $this->getDeleteAttachmentString()) {
                    $attachedFile->setToBeDeleted();
                    return;
                }

                /** @var StorableFileFactoryInterface $factory */
                $factory = app(StorableFileFactoryInterface::class);

                $attachedFile->setUploadedFile(
                    $factory->makeFromAny($value)
                );
            }

            $this->attachedUpdated = true;

            return;
        }

        parent::setAttribute($key, $value);
    }

    /**
     * Overridden to prevent attempts to persist attachment attributes directly.
     *
     * Reason this is required: Laravel 5.5 changed the getDirty() behavior.
     *
     * {@inheritdoc}
     */
    public function originalIsEquivalent($key)
    {
        if (array_key_exists($key, $this->attachedFiles)) {
            return true;
        }

        return parent::originalIsEquivalent($key);
    }

    /**
     * Return the image paths for a given attachment.
     *
     * @param string $attachmentName
     * @return string[]
     */
    public function pathsForAttachment($attachmentName)
    {
        $paths = [];

        if (isset($this->attachedFiles[ $attachmentName ])) {
            $attachment = $this->attachedFiles[ $attachmentName ];

            foreach ($attachment->variants(true) as $variant) {
                $paths[ $variant ] = $attachment->variantPath($variant);
            }
        }

        return $paths;
    }

    /**
     * Return the image urls for a given attachment.
     *
     * @param  string $attachmentName
     * @return array
     */
    public function urlsForAttachment($attachmentName)
    {
        $urls = [];

        if (isset($this->attachedFiles[ $attachmentName ])) {
            $attachment = $this->attachedFiles[ $attachmentName ];

            foreach ($attachment->variants(true) as $variant) {
                $urls[ $variant ] = $attachment->url($variant);
            }
        }

        return $urls;
    }

    /**
     * Marks that at least one attachment on the model has been updated and should be processed.
     */
    public function markAttachmentUpdated()
    {
        $this->attachedUpdated = true;
    }

    /**
     * Add the attached files to the model's attributes.
     */
    public function mergeFileAttributes()
    {
        $this->attributes += array_filter(
            $this->getAttachedFiles(),
            function (AttachmentInterface $attachment) {
                return $this->areAttachmentAttributesLoaded($attachment->name());
            }
        );
    }

    /**
     * Remove any attached file attributes so they aren't sent to the database.
     */
    public function removeFileAttributes()
    {
        foreach ($this->getAttachedFiles() as $key => $file) {
            unset($this->attributes[$key]);
        }
    }

    /**
     * Returns the string with which an attachment can be deleted.
     *
     * @return string
     */
    protected function getDeleteAttachmentString()
    {
        return config('paperclip.delete-hash', Attachment::NULL_ATTACHMENT);
    }
}
