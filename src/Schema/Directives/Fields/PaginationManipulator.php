<?php

namespace Nuwave\Lighthouse\Schema\Directives\Fields;

use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\AST\PartialParser;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Nuwave\Lighthouse\Schema\Types\PaginatorField;
use Nuwave\Lighthouse\Schema\Types\ConnectionField;
use Nuwave\Lighthouse\Exceptions\DirectiveException;

class PaginationManipulator
{
    // The default is offset-based pagination
    const PAGINATION_TYPE_PAGINATOR = 'paginator';
    const PAGINATION_ALIAS_DEFAULT = 'default';

    // Those are both aliases for a Connection style pagination
    const PAGINATION_TYPE_CONNECTION = 'connection';
    const PAGINATION_ALIAS_RELAY = 'relay';

    /**
     * Apply possible aliases and throw if the given pagination type is invalid.
     *
     * @param  string  $paginationType
     * @return string
     *
     * @throws \Nuwave\Lighthouse\Exceptions\DirectiveException
     */
    public static function assertValidPaginationType(string $paginationType): string
    {
        if ($paginationType === self::PAGINATION_ALIAS_RELAY) {
            return self::PAGINATION_TYPE_CONNECTION;
        }

        if ($paginationType === self::PAGINATION_ALIAS_DEFAULT) {
            return self::PAGINATION_TYPE_PAGINATOR;
        }

        if (in_array($paginationType, [
            self::PAGINATION_TYPE_PAGINATOR,
            self::PAGINATION_TYPE_CONNECTION,
        ])) {
            return $paginationType;
        }

        throw new DirectiveException("Found invalid pagination type: {$paginationType}");
    }

    /**
     * Transform the definition for a field to a field with pagination.
     *
     * This makes either an offset-based Paginator or a cursor-based Connection.
     * The types in between are automatically generated and applied to the schema.
     *
     * @param  string  $paginationType
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $fieldDefinition
     * @param  \GraphQL\Language\AST\ObjectTypeDefinitionNode  $parentType
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $current
     * @param  int|null  $defaultCount
     * @return \Nuwave\Lighthouse\Schema\AST\DocumentAST
     */
    public static function transformToPaginatedField(string $paginationType, FieldDefinitionNode $fieldDefinition, ObjectTypeDefinitionNode $parentType, DocumentAST $current, int $defaultCount = null): DocumentAST
    {
        switch (self::assertValidPaginationType($paginationType)) {
            case self::PAGINATION_TYPE_CONNECTION:
                return self::registerConnection($fieldDefinition, $parentType, $current, $defaultCount);
            case self::PAGINATION_TYPE_PAGINATOR:
            default:
                return self::registerPaginator($fieldDefinition, $parentType, $current, $defaultCount);
        }
    }

    /**
     * Register connection w/ schema.
     *
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $fieldDefinition
     * @param  \GraphQL\Language\AST\ObjectTypeDefinitionNode  $parentType
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @param  int|null  $defaultCount
     * @return \Nuwave\Lighthouse\Schema\AST\DocumentAST
     */
    public static function registerConnection(FieldDefinitionNode $fieldDefinition, ObjectTypeDefinitionNode $parentType, DocumentAST $documentAST, int $defaultCount = null): DocumentAST
    {
        $fieldTypeName = ASTHelper::getUnderlyingTypeName($fieldDefinition);
        $connectionTypeName = "{$fieldTypeName}Connection";
        $connectionEdgeName = "{$fieldTypeName}Edge";
        $connectionFieldName = addslashes(ConnectionField::class);

        $connectionType = PartialParser::objectTypeDefinition("
            type $connectionTypeName {
                pageInfo: PageInfo! @field(resolver: \"{$connectionFieldName}@pageInfoResolver\")
                edges: [$connectionEdgeName] @field(resolver: \"{$connectionFieldName}@edgeResolver\")
            }
        ");

        $connectionEdge = PartialParser::objectTypeDefinition("
            type $connectionEdgeName {
                node: $fieldTypeName
                cursor: String!
            }
        ");

        $countArgument = $defaultCount
            ? "first: Int = {$defaultCount}"
            : 'first: Int!';

        $inputValueDefinitions = [
            $countArgument,
            'after: String',
        ];

        $connectionArguments = PartialParser::inputValueDefinitions($inputValueDefinitions);

        $fieldDefinition->arguments = ASTHelper::mergeNodeList($fieldDefinition->arguments, $connectionArguments);
        $fieldDefinition->type = PartialParser::namedType($connectionTypeName);
        $parentType->fields = ASTHelper::mergeNodeList($parentType->fields, [$fieldDefinition]);

        $documentAST->setDefinition($connectionType);
        $documentAST->setDefinition($connectionEdge);
        $documentAST->setDefinition($parentType);

        return $documentAST;
    }

    /**
     * Register paginator w/ schema.
     *
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $fieldDefinition
     * @param  \GraphQL\Language\AST\ObjectTypeDefinitionNode  $parentType
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @param  int|null  $defaultCount
     * @return \Nuwave\Lighthouse\Schema\AST\DocumentAST
     */
    public static function registerPaginator(FieldDefinitionNode $fieldDefinition, ObjectTypeDefinitionNode $parentType, DocumentAST $documentAST, int $defaultCount = null): DocumentAST
    {
        $fieldTypeName = ASTHelper::getUnderlyingTypeName($fieldDefinition);
        $paginatorTypeName = "{$fieldTypeName}Paginator";
        $paginatorFieldClassName = addslashes(PaginatorField::class);

        $paginatorType = PartialParser::objectTypeDefinition("
            type $paginatorTypeName {
                paginatorInfo: PaginatorInfo! @field(resolver: \"{$paginatorFieldClassName}@paginatorInfoResolver\")
                data: [$fieldTypeName!]! @field(resolver: \"{$paginatorFieldClassName}@dataResolver\")
            }
        ");

        $countArgument = $defaultCount
            ? "count: Int = {$defaultCount}"
            : 'count: Int!';

        $inputValueDefinitions = [
            $countArgument,
            'page: Int',
        ];

        $paginationArguments = PartialParser::inputValueDefinitions($inputValueDefinitions);

        $fieldDefinition->arguments = ASTHelper::mergeNodeList($fieldDefinition->arguments, $paginationArguments);
        $fieldDefinition->type = PartialParser::namedType($paginatorTypeName);
        $parentType->fields = ASTHelper::mergeNodeList($parentType->fields, [$fieldDefinition]);

        $documentAST->setDefinition($paginatorType);
        $documentAST->setDefinition($parentType);

        return $documentAST;
    }
}
