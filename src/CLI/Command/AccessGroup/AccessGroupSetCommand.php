<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupEditor;

/**
 * Create an access group, or replace one, from a full definition.
 */
class AccessGroupSetCommand extends AccessGroupWriteCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('access-group:set')
			->setDescription('Create or replace an access group from a JSON definition (use "-" to read stdin)')
			->setHelp(<<<'HELP'
Create an access group, or replace an existing one, from a full definition.
Any permission section you leave out grants nothing.

  <info>tcms access-group:set authors authors.json</info>
  <info>tcms access-group:get editor --json | tcms access-group:set editor-copy -</info>

The definition:

  {
    "description": "Writes blog posts",
    "permissions": {
      "collections":     {"operations": ["create", "read", "update"], "all": false, "allowed": ["blog"]},
      "collectionsMeta": {"operations": ["read"], "all": true},
      "schemas":         {"operations": ["read"], "all": true},
      "utils":           {"all": false, "allowed": ["cache"]},
      "settings":        {"all": false, "allowed": []},
      "extensions":      {"all": true},
      "dataviews": false, "builder": false, "mailer": false,
      "playground": false, "docs": true, "inlineEdit": true
    }
  }

Operations are create, read, update and delete. An unknown section, key or
operation is refused. The admin group cannot be changed. Use
<info>access-group:patch</info> to change part of a group.
HELP);
	}

	protected function write(AccessGroupEditor $editor, string $id, array $data): AccessGroupData
	{
		return $editor->set($id, $data);
	}

	protected function verb(): string
	{
		return 'Saved';
	}
}
