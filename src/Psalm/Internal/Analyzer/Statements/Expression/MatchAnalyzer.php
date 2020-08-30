<?php
namespace Psalm\Internal\Analyzer\Statements\Expression;

use PhpParser;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Context;
use Psalm\Type;
use function strtolower;

class MatchAnalyzer
{
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\Match_ $stmt,
        Context $context
    ) : bool {
        $was_inside_call = $context->inside_call;

        $context->inside_call = true;

        $was_inside_conditional = $context->inside_conditional;

        $context->inside_conditional = true;

        if (ExpressionAnalyzer::analyze($statements_analyzer, $stmt->cond, $context) === false) {
            $context->inside_conditional = $was_inside_conditional;

            return false;
        }

        $context->inside_conditional = $was_inside_conditional;

        $switch_var_id = ExpressionIdentifier::getArrayVarId(
            $stmt->cond,
            null,
            $statements_analyzer
        );

        $match_condition = $stmt->cond;

        $fake_match_condition = false;

        if (!$switch_var_id
            && ($stmt->cond instanceof PhpParser\Node\Expr\FuncCall
                || $stmt->cond instanceof PhpParser\Node\Expr\MethodCall
                || $stmt->cond instanceof PhpParser\Node\Expr\StaticCall
            )
        ) {
            $switch_var_id = '$__tmp_switch__' . (int) $stmt->cond->getAttribute('startFilePos');

            $condition_type = $statements_analyzer->node_data->getType($stmt->cond) ?: Type::getMixed();

            $context->vars_in_scope[$switch_var_id] = $condition_type;

            if ($switch_var_id && substr($switch_var_id, 0, 15) === '$__tmp_switch__') {
                $match_condition = new PhpParser\Node\Expr\Variable(
                    substr($switch_var_id, 1),
                    $stmt->cond->getAttributes()
                );

                $fake_match_condition = true;
            }
        }

        $arms = $stmt->arms;

        foreach ($arms as $i => $arm) {
            // move default to the end
            if ($arm->conds === null) {
                unset($arms[$i]);
                $arms[] = $arm;
            }
        }

        $arms = array_reverse($arms);

        $last_arm = array_shift($arms);

        $old_node_data = $statements_analyzer->node_data;

        $statements_analyzer->node_data = clone $statements_analyzer->node_data;

        if ($last_arm->conds === null) {
            $ternary = $last_arm->body;
        } else {
            $ternary = new PhpParser\Node\Expr\Ternary(
                self::convertCondsToConditional($last_arm->conds, $match_condition, $last_arm->getAttributes()),
                $last_arm->body,
                new PhpParser\Node\Expr\Throw_(
                    new PhpParser\Node\Expr\New_(
                        new PhpParser\Node\Name\FullyQualified(
                            'UnhandledMatchError'
                        )
                    )
                )
            );
        }

        foreach ($arms as $arm) {
            if (!$arm->conds) {
                continue;
            }

            $ternary = new PhpParser\Node\Expr\Ternary(
                self::convertCondsToConditional($arm->conds, $match_condition, $arm->getAttributes()),
                $arm->body,
                $ternary,
                $arm->getAttributes()
            );
        }

        if (ExpressionAnalyzer::analyze($statements_analyzer, $ternary, $context) === false) {
            return false;
        }

        if ($stmt_expr_type = $statements_analyzer->node_data->getType($ternary)) {
            $old_node_data->setType($stmt, $stmt_expr_type ?: Type::getMixed());
        }

        $statements_analyzer->node_data = $old_node_data;

        $context->inside_call = $was_inside_call;

        return true;
    }

    /**
     * @param non-empty-list<PhpParser\Node\Expr> $conds
     */
    private static function convertCondsToConditional(
        array $conds,
        PhpParser\Node\Expr $match_condition,
        array $attributes
    ) : PhpParser\Node\Expr {
        if (count($conds) === 1) {
            return new PhpParser\Node\Expr\BinaryOp\Identical(
                $match_condition,
                $conds[0],
                $attributes
            );
        }

        $array_items = array_map(
            function ($cond) {
                return new PhpParser\Node\Expr\ArrayItem($cond, null, false, $cond->getAttributes());
            },
            $conds
        );

        return new PhpParser\Node\Expr\FuncCall(
            new PhpParser\Node\Name\FullyQualified(['in_array']),
            [
                new PhpParser\Node\Arg(
                    $match_condition
                ),
                new PhpParser\Node\Arg(
                    new PhpParser\Node\Expr\Array_(
                        $array_items
                    )
                ),
                new PhpParser\Node\Arg(
                    new PhpParser\Node\Expr\ConstFetch(
                        new PhpParser\Node\Name\FullyQualified(['true'])
                    )
                ),
            ],
            $attributes
        );
    }
}