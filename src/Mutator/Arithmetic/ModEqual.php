<?php

declare(strict_types=1);


namespace Infection\Mutator\Arithmetic;


use Infection\Mutator\Mutator;
use PhpParser\Node;

class ModEqual implements Mutator
{
    /**
     * Replaces "%=" with "*="
     *
     * @param Node $node
     * @return Node\Expr\AssignOp\Mul
     */
    public function mutate(Node $node)
    {
        return new Node\Expr\AssignOp\Mul($node->var, $node->expr, $node->getAttributes());
    }

    public function shouldMutate(Node $node): bool
    {
        return $node instanceof Node\Expr\AssignOp\Mod;
    }
}