<?php
namespace Neos\Neos\Ui\Domain\Model\Changes;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Neos\Ui\Domain\Model\AbstractChange;
use Neos\Neos\Ui\Domain\Model\ChangeInterface;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\Neos\Ui\Domain\Service\NodePropertyConversionService;
use Neos\Utility\ObjectAccess;

/**
 * Changes a property on a node
 */
class Property extends AbstractChange
{

    /**
     * @Flow\Inject
     * @var NodePropertyConversionService
     */
    protected $nodePropertyConversionService;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeServiceInterface
     */
    protected $nodeService;

    /**
     * The name of the property to be changed
     *
     * @var string
     */
    protected $propertyName;

    /**
     * The value, the property will be set to
     *
     * @var string
     */
    protected $value;

    /**
     * Set the property name
     *
     * @param string $propertyName
     * @return void
     */
    public function setPropertyName($propertyName)
    {
        $this->propertyName = $propertyName;
    }

    /**
     * Get the property name
     *
     * @return string
     */
    public function getPropertyName()
    {
        return $this->propertyName;
    }

    /**
     * Set the value
     *
     * @param string $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * Get the value
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Checks whether this change can be applied to the subject
     *
     * @return boolean
     */
    public function canApply()
    {
        $nodeType = $this->getSubject()->getNodeType();
        $propertyName = $this->getPropertyName();
        $nodeTypeProperties = $nodeType->getProperties();

        return isset($nodeTypeProperties[$propertyName]);
    }

    /**
     * Applies this change
     *
     * @return void
     */
    public function apply()
    {
        if ($this->canApply()) {
            $node = $this->getSubject();
            $propertyName = $this->getPropertyName();
            $value = $this->nodePropertyConversionService->convert(
                $node->getNodeType(),
                $propertyName,
                $this->getValue(),
                $node->getContext()
            );

            // TODO: Make changing the node type a separated, specific/defined change operation.
            if ($propertyName === '_nodeType') {
                $nodeType = $this->nodeTypeManager->getNodeType($value);
                $node = $this->changeNodeType($node, $nodeType);
            } elseif ($propertyName{0} === '_') {
                ObjectAccess::setProperty($node, substr($propertyName, 1), $value);
            } else {
                $node->setProperty($propertyName, $value);
            }

            $this->updateWorkspaceInfo();

            $reloadIfChangedConfigurationPath = sprintf('properties.%s.ui.reloadIfChanged', $propertyName);
            if ($node->getNodeType()->getConfiguration($reloadIfChangedConfigurationPath)) {
                $this->reloadDocument();
            }

            // This might be needed to update node label and other things that we can calculate only on the server
            $updateNodeInfo = new UpdateNodeInfo();
            $updateNodeInfo->setNode($node);
            $this->feedbackCollection->add($updateNodeInfo);
        }
    }

    /**
     * @param NodeInterface $node
     * @param NodeType $nodeType
     * @return NodeInterface
     */
    protected function changeNodeType(NodeInterface $node, NodeType $nodeType)
    {
        $oldNodeType = $node->getNodeType();
        ObjectAccess::setProperty($node, 'nodeType', $nodeType);
        $this->nodeService->cleanUpProperties($node);
        $this->nodeService->cleanUpAutoCreatedChildNodes($node, $oldNodeType);
        $this->nodeService->createChildNodes($node);

        return $node;
    }
}
