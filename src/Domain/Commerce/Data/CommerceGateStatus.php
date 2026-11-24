<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

/**
 * Why commerce is, or is not, available on this site. The gate's message is
 * chosen from it.
 */
enum CommerceGateStatus: string
{
	case Allowed = 'allowed';

	/** The edition is below Pro. There is no add-on to buy yet. */
	case NeedsPro = 'needs_pro';

	/** A Pro site that has not bought the Commerce add-on. */
	case NeedsEntitlement = 'needs_entitlement';

	public const MESSAGE = 'Commerce requires Total CMS Pro and the Commerce add-on.';

	/** Where a Pro site without the add-on is sent to add it. */
	public const PURCHASE_URL = 'https://totalcms.co/commerce';

	/**
	 * What to tell someone the gate turned away, or '' when it let them in.
	 * A Pro site is one purchase away, so it is told where.
	 */
	public function refusal(): string
	{
		return match ($this) {
			self::Allowed          => '',
			self::NeedsPro         => self::MESSAGE,
			self::NeedsEntitlement => self::MESSAGE . ' Add Commerce: ' . self::PURCHASE_URL,
		};
	}
}
