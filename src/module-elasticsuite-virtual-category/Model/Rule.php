<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Elastic Suite to newer
 * versions in the future.
 *
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteVirtualCategory
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2016 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\ElasticsuiteVirtualCategory\Model;

use Magento\Store\Model\StoreManagerInterface;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Smile\ElasticsuiteVirtualCategory\Api\Data\VirtualRuleInterface;

/**
 * Virtual category rule.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @category Smile
 * @package  Smile\ElasticsuiteVirtualCategory
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class Rule extends \Smile\ElasticsuiteCatalogRule\Model\Rule implements VirtualRuleInterface
{
    /**
     * @var \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory
     */
    private $queryFactory;

    /**
     * @var \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\ProductFactory
     */
    private $productConditionsFactory;

    /**
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    private $categoryFactory;

    /**
     * @var \Smile\ElasticsuiteVirtualCategory\Model\ResourceModel\VirtualCategory\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\Product\QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * Constructor.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @param \Magento\Framework\Model\Context                                                         $context                   Context.
     * @param \Magento\Framework\Registry                                                              $registry                  Registry.
     * @param \Magento\Framework\Data\FormFactory                                                      $formFactory               Form factory.
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface                                     $localeDate                Locale date.
     * @param \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\CombineFactory                       $combineConditionsFactory  Search engine rule (combine) condition factory.
     * @param \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\ProductFactory                       $productConditionsFactory  Search engine rule (product) condition factory.
     * @param \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory                                $queryFactory              Search query factory.
     * @param \Magento\Catalog\Model\CategoryFactory                                                   $categoryFactory           Product category factorty.
     * @param \Smile\ElasticsuiteVirtualCategory\Model\ResourceModel\VirtualCategory\CollectionFactory $categoryCollectionFactory Virtual categories collection factory.
     * @param \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\Product\QueryBuilder                 $queryBuilder              Search rule query builder.
     * @param StoreManagerInterface                                                                    $storeManagerInterface     Store Manager
     * @param array                                                                                    $data                      Additional data.
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\CombineFactory $combineConditionsFactory,
        \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\ProductFactory $productConditionsFactory,
        \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        \Smile\ElasticsuiteVirtualCategory\Model\ResourceModel\VirtualCategory\CollectionFactory $categoryCollectionFactory,
        \Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\Product\QueryBuilder $queryBuilder,
        StoreManagerInterface $storeManagerInterface,
        array $data = []
    ) {
        $this->queryFactory              = $queryFactory;
        $this->productConditionsFactory  = $productConditionsFactory;
        $this->categoryFactory           = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->queryBuilder              = $queryBuilder;
        $this->storeManager              = $storeManagerInterface;

        parent::__construct($context, $registry, $formFactory, $localeDate, $combineConditionsFactory, $data);
    }

    /**
     * Implementation of __toString().
     * This one is mandatory to ensure the object is properly handled when it get sent "as is" to a DB query.
     * This occurs especially in @see \Magento\Catalog\Model\Indexer\Category\Flat\Action\Rows::reindex() (line 86 & 98)
     *
     * @return string
     */
    public function __toString()
    {
        return serialize($this->getConditions()->asArray());
    }

    /**
     * Build search query by category.
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface $category           Search category.
     * @param array                                       $excludedCategories Categories that should not be used into search query building.
     *                                                                        Used to avoid infinite recursion while building virtual categories rules.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface
     */
    public function getCategorySearchQuery($category, $excludedCategories = [])
    {
        $query         = null;

        if (!is_object($category)) {
            $category = $this->categoryFactory->create()->setStoreId($this->getStoreId())->load($category);
        }

        if (!in_array($category->getId(), $excludedCategories)) {
            $excludedCategories[] = $category->getId();

            if ((bool) $category->getIsVirtualCategory() && $category->getIsActive()) {
                $query = $this->getVirtualCategoryQuery($category, $excludedCategories);
            } elseif ($category->getId() && $category->getIsActive()) {
                $query = $this->getStandardCategoryQuery($category, $excludedCategories);
            }
            if ($query && $category->hasChildren()) {
                $query = $this->addChildrenQueries($query, $category, $excludedCategories);
            }
        }

        return $query;
    }

    /**
     * Retrieve search queries of children categories.
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface $rootCategory Root category.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface[]
     */
    public function getSearchQueriesByChildren(CategoryInterface $rootCategory)
    {
        $queries     = [];
        $childrenIds = $rootCategory->getResource()->getChildren($rootCategory, false);

        if (!empty($childrenIds)) {
            $storeId            = $this->getStoreId();
            $categoryCollection = $this->categoryCollectionFactory->create()->setStoreId($storeId);

            $categoryCollection
                ->setStoreId($this->getStoreId())
                ->addIsActiveFilter()
                ->addIdFilter($childrenIds)
                ->addAttributeToSelect(['virtual_category_root', 'is_virtual_category', 'virtual_rule']);

            foreach ($categoryCollection as $category) {
                $childQuery = $this->getCategorySearchQuery($category);
                if ($childQuery !== null) {
                    $queries[$category->getId()] = $childQuery;
                }
            }
        }

        return $queries;
    }

    /**
     * Load the root category used for a virtual category.
     *
     * @param CategoryInterface $category Virtual category.
     *
     * @return CategoryInterface
     */
    private function getVirtualRootCategory(CategoryInterface $category)
    {
        $storeId      = $this->getStoreId();
        $rootCategory = $this->categoryFactory->create()->setStoreId($storeId);

        if ($category->getVirtualCategoryRoot() !== null && !empty($category->getVirtualCategoryRoot())) {
            $rootCategoryId = $category->getVirtualCategoryRoot();
            $rootCategory->load($rootCategoryId);
        }

        if ($rootCategory && $rootCategory->getId()
            && ($rootCategory->getLevel() < 1 || (int) $rootCategory->getId() === (int) $this->getRootCategoryId($storeId))
        ) {
            $rootCategory = null;
        }

        return $rootCategory;
    }

    /**
     * Transform a category in query rule.
     *
     * @param CategoryInterface $category           Category.
     * @param array             $excludedCategories Categories ignored in subquery filters.
     *
     * @return QueryInterface
     */
    private function getStandardCategoryQuery(CategoryInterface $category, $excludedCategories = [])
    {
        return $this->getStandardCategoriesQuery([$category->getId()], $excludedCategories);
    }

    /**
     * Transform a category ids list in query rule.
     *
     * @param array $categoryIds        Categories included in the query.
     * @param array $excludedCategories Categories ignored in subquery filters.
     *
     * @return QueryInterface
     */
    private function getStandardCategoriesQuery(array $categoryIds, $excludedCategories)
    {
        $conditionsParams  = ['data' => ['attribute' => 'category_ids', 'operator' => '()', 'value' => $categoryIds]];
        $categoryCondition = $this->productConditionsFactory->create($conditionsParams);

        return $this->queryBuilder->getSearchQuery($categoryCondition, $excludedCategories);
    }

    /**
     * Transform the virtual category into a QueryInterface used for filtering.
     *
     * @param CategoryInterface $category           Virtual category.
     * @param array             $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return QueryInterface
     */
    private function getVirtualCategoryQuery(CategoryInterface $category, $excludedCategories = [])
    {
        $query          = $category->getVirtualRule()->getConditions()->getSearchQuery($excludedCategories);
        $parentCategory = $this->getVirtualRootCategory($category);

        if ($parentCategory && in_array($parentCategory->getId(), $excludedCategories)) {
            $query = null;
        } if ($parentCategory && $parentCategory->getId()) {
            $parentQuery = $this->getCategorySearchQuery($parentCategory, $excludedCategories);
            if ($parentQuery) {
                $query = $this->queryFactory->create(QueryInterface::TYPE_BOOL, ['must' => [$query, $parentQuery]]);
            }
        }

        return $query;
    }

    /**
     * Append children queries to the rule.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * @param QueryInterface|NULL $query              Base query.
     * @param CategoryInterface   $category           Current cayegory.
     * @param array               $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface
     */
    private function addChildrenQueries($query, CategoryInterface $category, $excludedCategories = [])
    {
        $childrenCategories    = $this->getChildrenCategories($category, $excludedCategories);
        $childrenCategoriesIds = [];

        if ($childrenCategories->getSize() > 0 && $query !== null) {
            $queryParams = ['should' => [$query], 'cached' => empty($excludedCategories)];

            foreach ($childrenCategories as $childrenCategory) {
                if (((bool) $childrenCategory->getIsVirtualCategory()) === true) {
                    $childrenQuery = $this->getCategorySearchQuery($childrenCategory, $excludedCategories);
                    if ($childrenQuery !== null) {
                        $queryParams['should'][] = $childrenQuery;
                    }
                } else {
                    $childrenCategoriesIds[] = $childrenCategory->getId();
                }
            }

            if (!empty($childrenCategoriesIds)) {
                $queryParams['should'][] = $this->getStandardCategoriesQuery($childrenCategoriesIds, $excludedCategories);
            }

            if (count($queryParams['should']) > 1) {
                $query = $this->queryFactory->create(QueryInterface::TYPE_BOOL, $queryParams);
            }
        }

        return $query;
    }

    /**
     * Returns the list of the virtual categories available under a category.
     *
     * @param CategoryInterface $category           Category.
     * @param array             $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return \Magento\Catalog\Model\ResourceModel\Category\Collection;
     */
    private function getChildrenCategories(CategoryInterface $category, $excludedCategories = [])
    {
        $storeId            = $category->getStoreId();
        $categoryCollection = $this->categoryCollectionFactory->create()->setStoreId($storeId);

        $categoryCollection->addIsActiveFilter()->addPathFilter(sprintf('%s/.*', $category->getPath()));

        if (((bool) $category->getIsVirtualCategory()) === false) {
            $categoryCollection->addFieldToFilter('is_virtual_category', '1');
        }

        if (!empty($excludedCategories)) {
            $categoryCollection->addAttributeToFilter('entity_id', ['nin' => $excludedCategories]);
        }

        $categoryCollection->addAttributeToSelect(['is_active', 'virtual_category_root', 'is_virtual_category', 'virtual_rule']);

        return $categoryCollection;
    }

    /**
     * Retrieve store root category id.
     *
     * @param \Magento\Store\Api\Data\StoreInterface|int|string $store Store id.
     *
     * @return integer
     */
    private function getRootCategoryId($store)
    {
        if (is_numeric($store) || is_string($store)) {
            $store = $this->storeManager->getStore($store);
        }

        $storeGroupId = $store->getStoreGroupId();

        return $this->storeManager->getGroup($storeGroupId)->getRootCategoryId();
    }
}
