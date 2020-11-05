<?php
namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\Storage\AttributeStorage;
use Psalm\Internal\Scanner\UnresolvedConstantComponent;
use Psalm\Issue\InvalidAttribute;
use Psalm\Type\Union;
use function reset;

class AttributeAnalyzer
{
    /**
     * @param  array<string>    $suppressed_issues
     * @param  1|2|4|8|16|32 $target
     */
    public static function analyze(
        SourceAnalyzer $source,
        AttributeStorage $attribute,
        array $suppressed_issues,
        int $target
    ) : void {
        if (ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
            $source,
            $attribute->fq_class_name,
            $attribute->location,
            null,
            null,
            $suppressed_issues,
            false,
            false,
            false,
            false,
            true
        ) === false) {
            return;
        }

        $codebase = $source->getCodebase();

        if (!$codebase->classlikes->classExists($attribute->fq_class_name)) {
            return;
        }

        self::checkAttributeTargets($source, $attribute, $target);

        $node_args = [];

        foreach ($attribute->args as $storage_arg) {
            $type = $storage_arg->type;

            if ($type instanceof UnresolvedConstantComponent) {
                $type = new Union([
                    \Psalm\Internal\Codebase\ConstantTypeResolver::resolve(
                        $codebase->classlikes,
                        $type,
                        $source instanceof \Psalm\Internal\Analyzer\StatementsAnalyzer ? $source : null
                    )
                ]);
            }

            if ($type->isMixed()) {
                return;
            }

            $node_args[] = new PhpParser\Node\Arg(
                \Psalm\Internal\Stubs\Generator\StubsGenerator::getExpressionFromType(
                    $type
                ),
                false,
                false,
                [
                    'startFilePos' => $storage_arg->location->raw_file_start,
                    'endFilePos' => $storage_arg->location->raw_file_end,
                    'startLine' => $storage_arg->location->raw_line_number
                ],
                $storage_arg->name
                    ? new PhpParser\Node\Identifier(
                        $storage_arg->name,
                        [
                            'startFilePos' => $storage_arg->location->raw_file_start,
                            'endFilePos' => $storage_arg->location->raw_file_end,
                            'startLine' => $storage_arg->location->raw_line_number
                        ]
                    )
                    : null
            );
        }

        $new_stmt = new PhpParser\Node\Expr\New_(
            new PhpParser\Node\Name\FullyQualified(
                $attribute->fq_class_name,
                [
                    'startFilePos' => $attribute->name_location->raw_file_start,
                    'endFilePos' => $attribute->name_location->raw_file_end,
                    'startLine' => $attribute->name_location->raw_line_number
                ]
            ),
            $node_args,
            [
                'startFilePos' => $attribute->location->raw_file_start,
                'endFilePos' => $attribute->location->raw_file_end,
                'startLine' => $attribute->location->raw_line_number
            ]
        );

        $statements_analyzer = new StatementsAnalyzer(
            $source,
            new \Psalm\Internal\Provider\NodeDataProvider()
        );

        $statements_analyzer->analyze(
            [new PhpParser\Node\Stmt\Expression($new_stmt)],
            new \Psalm\Context()
        );
    }

    /**
     * @param  1|2|4|8|16|32 $target
     */
    private static function checkAttributeTargets(
        SourceAnalyzer $source,
        AttributeStorage $attribute,
        int $target
    ) : void {
        $codebase = $source->getCodebase();

        $attribute_class_storage = $codebase->classlike_storage_provider->get($attribute->fq_class_name);

        if ($attribute_class_storage->attributes) {
            foreach ($attribute_class_storage->attributes as $attribute_attribute) {
                if ($attribute_attribute->fq_class_name === 'Attribute') {
                    if (!$attribute_attribute->args) {
                        return;
                    }

                    $first_arg = reset($attribute_attribute->args);

                    $first_arg_type = $first_arg->type;

                    if ($first_arg_type instanceof UnresolvedConstantComponent) {
                        $first_arg_type = new Union([
                            \Psalm\Internal\Codebase\ConstantTypeResolver::resolve(
                                $codebase->classlikes,
                                $first_arg_type,
                                $source instanceof \Psalm\Internal\Analyzer\StatementsAnalyzer ? $source : null
                            )
                        ]);
                    }

                    if (!$first_arg_type->isSingleIntLiteral()) {
                        return;
                    }

                    $acceptable_mask = $first_arg_type->getSingleIntLiteral()->value;

                    if (($acceptable_mask & $target) !== $target) {
                        $target_map = [
                            1 => 'class',
                            2 => 'function',
                            4 => 'method',
                            8 => 'property',
                            16 => 'class constant',
                            32 => 'function/method parameter'
                        ];

                        if (\Psalm\IssueBuffer::accepts(
                            new InvalidAttribute(
                                'This attribute can not be used on a ' . $target_map[$target],
                                $attribute->name_location
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }
        }
    }
}
