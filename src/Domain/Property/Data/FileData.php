<?php

namespace App\Domain\Property\Data;

use UnexpectedValueException;

/**
 * String type property data.
 */
class FileData extends PropertyData
{
    public ListData     $tags;
    public PasswordData $password;
    public DateData     $uploadDate;
    public bool         $protected;
    public string       $mime;
    public string       $label;
    public string       $name;
    public string       $comments;
    public int          $size;

    public function __construct(array $file = [])
    {
        $this->protected = $file['protected'] ?? false;
        $this->label     = $file['label'] ?? '';
        $this->mime      = $file['mime'] ?? '';
        $this->name      = $file['name'] ?? '';
        $this->comments  = $file['comments'] ?? '';
        $this->size      = $file['size'] ?? 0;
        $this->tags      = new ListData($file['tags'] ?? []);
        $this->password  = new PasswordData($file['password'] ?? '');

        $uploadDate       = empty($file['uploadDate']) ? date('c') : $file['uploadDate'];
        $this->uploadDate = new DateData($uploadDate);

        if ($this->protected && empty($this->password->hash)) {
            throw new UnexpectedValueException('Password is required for protected file');
        }
    }

    public function transform(): array
    {
        return [
            'tags'       => $this->tags->transform(),
            'password'   => $this->password->transform(),
            'uploadDate' => $this->uploadDate->transform(),
            'protected'  => $this->protected,
            'mime'       => $this->mime,
            'label'      => $this->label,
            'name'       => $this->name,
            'comments'   => $this->comments,
            'size'       => $this->size,
        ];
    }
}
