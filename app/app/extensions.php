<?php

declare(strict_types=1);

use function Nafinity\extensions;

/**
 * The host's last word on contributions.
 *
 * This file runs after every extension provider, so whatever it says here wins:
 * an installation can replace or remove a definition an installed package
 * registered, without changing that package.
 *
 * It is optional. Delete it and nothing changes.
 *
 * Removing a contribution removes its surface only. It deletes no stored value,
 * no file and nobody's permission — those are separate, deliberate decisions.
 *
 *     extensions()->ui()->remove('example.review.notes');
 *     extensions()->views()->add(new ViewOverride('ticket', 'local/ticket'), true);
 *     extensions()->navigation()->remove('core.notifications');
 *
 * The three fixed ticket areas stay fixed even here: title, description and
 * comments answer an attempt to replace or remove them with a LogicException.
 */
