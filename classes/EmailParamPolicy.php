<?php

namespace Grav\Plugin\Email;

use Twig\Sandbox\SecurityPolicyInterface;

/**
 * Twig sandbox policy for email action parameters.
 *
 * Email parameters (subject, body, recipients, ...) come from a page's
 * `form.process.email.*` front matter, so they are editor-authored and are
 * rendered under the Twig content sandbox. (GHSA-gh8j-q67c-j53f)
 *
 * They are not, however, rendered into a browser DOM. A handful of filters
 * that the content sandbox withholds because they defeat output escaping are
 * therefore both safe and necessary here: an email address in the documented
 * `My Name <me@example.com>` form has to reach the transport unescaped, or
 * Symfony rejects it. That is why the docs tell authors to write
 * `{{ config.site.emails.sales|raw }}`.
 *
 * Rather than rebuild the policy, this delegates to the live content policy
 * and only removes the extra filters from the filter check. Every class,
 * method and property restriction is inherited untouched, including any added
 * later, so this cannot drift out of sync with the sandbox it wraps.
 */
final class EmailParamPolicy implements SecurityPolicyInterface
{
    /**
     * @param SecurityPolicyInterface $inner The live content sandbox policy.
     * @param list<string> $extraFilters Filters permitted for email parameters
     *                                   on top of the content allowlist. Keep
     *                                   this list short and deliberate: each
     *                                   entry is a judgement that the filter is
     *                                   harmless when the output is an email
     *                                   rather than a web page.
     */
    public function __construct(
        private readonly SecurityPolicyInterface $inner,
        private readonly array $extraFilters = []
    ) {
    }

    /**
     * @param array $tags
     * @param array $filters
     * @param array $functions
     * @return void
     */
    public function checkSecurity($tags, $filters, $functions): void
    {
        if ($this->extraFilters) {
            $filters = array_values(array_diff($filters, $this->extraFilters));
        }

        $this->inner->checkSecurity($tags, $filters, $functions);
    }

    /**
     * @param object $obj
     * @param string $method
     * @return void
     */
    public function checkMethodAllowed($obj, $method): void
    {
        $this->inner->checkMethodAllowed($obj, $method);
    }

    /**
     * @param object $obj
     * @param string $property
     * @return void
     */
    public function checkPropertyAllowed($obj, $property): void
    {
        $this->inner->checkPropertyAllowed($obj, $property);
    }
}
