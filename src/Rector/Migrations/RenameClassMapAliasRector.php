<?php

declare(strict_types=1);

namespace Ssch\TYPO3Rector\Rector\Migrations;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\CodingStyle\Naming\ClassNaming;
use Rector\Core\PhpDoc\PhpDocClassRenamer;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\ConfiguredCodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\ClassExistenceStaticHelper;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStan\Type\FullyQualifiedObjectType;
use Rector\Renaming\Exception\InvalidPhpCodeException;
use ReflectionClass;

final class RenameClassMapAliasRector extends AbstractRector
{
    /**
     * @var class-string[]
     */
    private $oldToNewClasses = [];

    /**
     * @var class-string[]
     */
    private $alreadyProcessedClasses = [];

    /**
     * @var ClassNaming
     */
    private $classNaming;

    /**
     * @var PhpDocClassRenamer
     */
    private $phpDocClassRenamer;

    public function __construct(
        ClassNaming $classNaming,
        PhpDocClassRenamer $phpDocClassRenamer,
        array $classAliasMaps = []
    ) {
        $this->classNaming = $classNaming;
        $this->phpDocClassRenamer = $phpDocClassRenamer;

        foreach ($classAliasMaps as $file) {
            $filePath = realpath(__DIR__ . '/' . $file);

            if (false !== $filePath && file_exists($filePath)) {
                $classAliasMap = require $filePath;

                foreach ($classAliasMap as $oldClass => $newClass) {
                    $this->oldToNewClasses[$oldClass] = $newClass;
                }
            }
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Replaces defined classes by new ones.', [
            new ConfiguredCodeSample(
                <<<'PHP'
namespace App;

use t3lib_div;

function someFunction()
{
    t3lib_div::makeInstance(\tx_cms_BackendLayout::class);
}
PHP
                ,
                <<<'PHP'
namespace App;

use TYPO3\CMS\Core\Utility\GeneralUtility;

function someFunction()
{
    GeneralUtility::makeInstance(\TYPO3\CMS\Backend\View\BackendLayoutView::class);
}
PHP
                ,
                [
                    'oldClassAliasMap' => 'config/Migrations/Code/ClassAliasMap.php',
                ]
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [
            Name::class,
            Property::class,
            FunctionLike::class,
            Expression::class,
            ClassLike::class,
            Namespace_::class,
        ];
    }

    /**
     * @param Name|FunctionLike|Property $node
     */
    public function refactor(Node $node): ?Node
    {
        $this->refactorPhpDoc($node);

        if ($node instanceof Name) {
            return $this->refactorName($node);
        }

        if ($node instanceof Namespace_) {
            return $this->refactorNamespaceNode($node);
        }

        if ($node instanceof ClassLike) {
            return $this->refactorClassLikeNode($node);
        }

        return null;
    }

    /**
     * Checks validity:.
     *
     * - extends SomeClass
     * - extends SomeInterface
     *
     * - new SomeClass
     * - new SomeInterface
     *
     * - implements SomeInterface
     * - implements SomeClass
     */
    private function isClassToInterfaceValidChange(Node $node, string $newName): bool
    {
        // ensure new is not with interface
        $parentNode = $node->getAttribute(AttributeKey::PARENT_NODE);
        if ($parentNode instanceof New_ && interface_exists($newName)) {
            return false;
        }

        if ($parentNode instanceof Class_) {
            return $this->isValidClassNameChange($node, $newName, $parentNode);
        }

        // prevent to change to import, that already exists
        if ($parentNode instanceof UseUse) {
            return $this->isValidUseImportChange($newName, $parentNode);
        }

        return true;
    }

    private function isValidUseImportChange(string $newName, UseUse $useUse): bool
    {
        /** @var Use_[]|null $useNodes */
        $useNodes = $useUse->getAttribute(AttributeKey::USE_NODES);
        if (null === $useNodes) {
            return true;
        }

        foreach ($useNodes as $useNode) {
            if ($this->isName($useNode, $newName)) {
                // name already exists
                return false;
            }
        }

        return true;
    }

    private function isValidClassNameChange(Node $node, string $newName, Class_ $classNode): bool
    {
        if ($classNode->extends === $node && interface_exists($newName)) {
            return false;
        }

        return ! (in_array($node, $classNode->implements, true) && class_exists($newName));
    }

    private function refactorNamespaceNode(Namespace_ $namespace): ?Node
    {
        $name = $this->getName($namespace);
        if (null === $name) {
            return null;
        }

        $classNode = $this->getClassOfNamespaceToRefactor($namespace);
        if (null === $classNode) {
            return null;
        }

        $newClassFqn = $this->oldToNewClasses[$this->getName($classNode)];
        $newNamespace = $this->classNaming->getNamespace($newClassFqn);

        // Renaming to class without namespace (example MyNamespace\DateTime -> DateTimeImmutable)
        if (! $newNamespace) {
            $classNode->name = new Identifier($newClassFqn);

            return $classNode;
        }

        $namespace->name = new Name($newNamespace);

        return $namespace;
    }

    private function getClassOfNamespaceToRefactor(Namespace_ $namespace): ?ClassLike
    {
        $foundClass = $this->betterNodeFinder->findFirst($namespace, function (Node $node): bool {
            if (! $node instanceof ClassLike) {
                return false;
            }

            $classLikeName = $this->getName($node);

            return isset($this->oldToNewClasses[$classLikeName]);
        });

        return $foundClass instanceof ClassLike ? $foundClass : null;
    }

    private function refactorClassLikeNode(ClassLike $classLike): ?Node
    {
        /** @var class-string|null $name */
        $name = $this->getName($classLike);
        if (null === $name) {
            return null;
        }

        $newName = $this->oldToNewClasses[$name] ?? null;
        if (null === $newName) {
            return null;
        }

        // prevents re-iterating same class in endless loop
        if (in_array($name, $this->alreadyProcessedClasses, true)) {
            return null;
        }

        $this->alreadyProcessedClasses[] = $name;

        $newName = $this->oldToNewClasses[$name];
        $newClassNamePart = $this->classNaming->getShortName($newName);
        $newNamespacePart = $this->classNaming->getNamespace($newName);

        $this->ensureClassWillNotBeDuplicate($newName, $name);

        $classLike->name = new Identifier($newClassNamePart);

        // Old class did not have any namespace, we need to wrap class with Namespace_ node
        if ($newNamespacePart && ! $this->classNaming->getNamespace($name)) {
            $this->changeNameToFullyQualifiedName($classLike);

            return new Namespace_(new Name($newNamespacePart), [$classLike]);
        }

        return $classLike;
    }

    private function refactorName(Name $name): ?Name
    {
        $stringName = $this->getName($name);
        if (null === $stringName) {
            return null;
        }

        $newName = $this->oldToNewClasses[$stringName] ?? null;
        if (null === $newName) {
            return null;
        }

        if (! $this->isClassToInterfaceValidChange($name, $newName)) {
            return null;
        }

        $parentNode = $name->getAttribute(AttributeKey::PARENT_NODE);
        // no need to preslash "use \SomeNamespace" of imported namespace
        if ($parentNode instanceof UseUse && (Use_::TYPE_NORMAL === $parentNode->type || Use_::TYPE_UNKNOWN === $parentNode->type)) {
            return new Name($newName);
        }

        return new FullyQualified($newName);
    }

    /**
     * Replace types in @var/@param/@return/@throws,
     * Doctrine @ORM entity targetClass, Serialize, Assert etc.
     */
    private function refactorPhpDoc(Node $node): void
    {
        /** @var PhpDocInfo|null $nodePhpDocInfo */
        $nodePhpDocInfo = $node->getAttribute(AttributeKey::PHP_DOC_INFO);
        if (null === $nodePhpDocInfo) {
            return;
        }

        if (! $this->docBlockManipulator->hasNodeTypeTags($node)) {
            return;
        }

        foreach ($this->oldToNewClasses as $oldClass => $newClass) {
            $oldClassType = new ObjectType($oldClass);
            $newClassType = new FullyQualifiedObjectType($newClass);

            $this->docBlockManipulator->changeType($node, $oldClassType, $newClassType);
        }

        $this->phpDocClassRenamer->changeTypeInAnnotationTypes($node, $this->oldToNewClasses);
    }

    /**
     * @param class-string $newName
     * @param class-string $oldName
     */
    private function ensureClassWillNotBeDuplicate(string $newName, string $oldName): void
    {
        if (! ClassExistenceStaticHelper::doesClassLikeExist($newName)) {
            return;
        }

        $classReflection = new ReflectionClass($newName);

        throw new InvalidPhpCodeException(sprintf(
            'Renaming class "%s" to "%s" would create a duplicated class/interface/trait (already existing in "%s") and cause PHP code to be invalid.',
            $oldName,
            $newName,
            $classReflection->getFileName()
        ));
    }

    private function changeNameToFullyQualifiedName(ClassLike $classLike): void
    {
        $this->traverseNodesWithCallable($classLike, function (Node $node) {
            if (! $node instanceof FullyQualified) {
                return null;
            }

            // invoke override
            $node->setAttribute(AttributeKey::ORIGINAL_NODE, null);
        });
    }
}
