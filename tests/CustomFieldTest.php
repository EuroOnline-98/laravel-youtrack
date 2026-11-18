<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\CustomField;
use E98\YouTrack\Data\Issue;
use E98\YouTrack\Support\FieldValue;
use PHPUnit\Framework\TestCase;

class CustomFieldTest extends TestCase
{
    public function test_builders_add_the_required_type(): void
    {
        $this->assertSame(
            ['name' => 'Priority', '$type' => 'SingleEnumIssueCustomField', 'value' => ['name' => 'Critical']],
            CustomField::enum('Priority', 'Critical'),
        );
        $this->assertSame(
            ['name' => 'State', '$type' => 'StateIssueCustomField', 'value' => ['name' => 'Open']],
            CustomField::state('State', 'Open'),
        );
        $this->assertSame(
            ['name' => 'Assignee', '$type' => 'SingleUserIssueCustomField', 'value' => ['login' => 'anna']],
            CustomField::user('Assignee', 'anna'),
        );
        $this->assertNull(CustomField::user('Assignee', null)['value']);
        $this->assertSame(
            ['name' => 'Notes', '$type' => 'TextIssueCustomField', 'value' => ['text' => 'hi']],
            CustomField::text('Notes', 'hi'),
        );
        $this->assertSame(
            ['name' => 'Tags', '$type' => 'MultiEnumIssueCustomField', 'value' => [['name' => 'a'], ['name' => 'b']]],
            CustomField::multiEnum('Tags', ['a', 'b']),
        );
        $this->assertSame(
            ['name' => 'Score', '$type' => 'SimpleIssueCustomField', 'value' => 5],
            CustomField::simple('Score', 5),
        );
    }

    public function test_field_values_are_normalized(): void
    {
        $this->assertSame('Open', FieldValue::normalize(['$type' => 'StateBundleElement', 'name' => 'Open']));
        $this->assertSame('anna', FieldValue::normalize(['login' => 'anna', 'fullName' => 'Anna']));
        $this->assertSame('2h 30m', FieldValue::normalize(['presentation' => '2h 30m', 'minutes' => 150]));
        $this->assertSame(['a', 'b'], FieldValue::normalize([['name' => 'a'], ['name' => 'b']]));
        $this->assertSame(7, FieldValue::normalize(7));
        $this->assertNull(FieldValue::normalize(null));
    }

    public function test_issue_tolerates_missing_optional_data(): void
    {
        $issue = Issue::fromArray(['id' => '2-1']);

        $this->assertSame('2-1', $issue->key);
        $this->assertSame('', $issue->summary);
        $this->assertNull($issue->state);
        $this->assertFalse($issue->isResolved());
        $this->assertNull($issue->reporter);
        $this->assertNull($issue->url);
        $this->assertNull($issue->created);
    }
}
