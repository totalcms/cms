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
    public int          $size;

    public function __construct(string $id, array $file)
    {
        $this->id        = $id;
        $this->protected = $file['protected'];
        $this->label     = $file['label'];
        $this->mime      = $file['mime'];
        $this->name      = $file['name'];
        $this->size      = $file['size'];
        $this->tags      = new ListData('tags', $file['tags'] ?? []);
        $this->password  = new PasswordData('password', $file['password'] ?? '');

        $uploadDate       = empty($file['uploadDate']) ? date('c') : $file['uploadDate'];
        $this->uploadDate = new DateData('uploadDate', $uploadDate);

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
            'size'       => $this->size,
        ];
    }
}
