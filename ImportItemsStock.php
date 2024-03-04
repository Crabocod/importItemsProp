<?php
namespace Itgrade\Tools\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Itgrade\Tools\AppConsole;
use Itgrade\Tools\Iblock;

class ImportItemsStock extends AppConsole
{
    const NAME = 'import_items_stock';
    const DESC = 'Проставление свойства склад товарам из csv';
    const PROP_NAME = 'PROPERTY_STOCK';
    const HL_BLOCK_TABLE_NAME = 'reference_stocks';

    const FILE_NAME = 'items_stock.csv';

    /**
     * @return void
     * @throws \Exception
     */
    public function configure()
    {
        parent::configure();
        $this->addArgument('xml_id', InputArgument::REQUIRED, 'xml_id склада');
    }

    /**
     * @param InputInterface $input,
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            if (!file_exists($this->getFilePath())){
                $this->io->note('Файл не найден');
                return;
            }
            if (!$this->stockExist((string)$input->getArgument('xml_id'))){
                throw new \Exception('Склад не найден');
            }

            $file = fopen($this->getFilePath(), 'r');

            while (($data = fgetcsv($file, 0, ";")) !== FALSE) {
                try {
                    $product_id = reset($data);
                    if (!$this->productExist($product_id)){
                        $this->io->note('Товар не найден');
                    }

                    $this->setProductStock($product_id, $input->getArgument('xml_id'));
                } catch (\Throwable $e) {
                    $this->io->warning($e->getMessage());
                }
            }

            fclose($file);

            $output->writeln('Success');
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());
        }

    }

    /**
     * @param int $id
     * @param string $xml_id
     * @return void
     */
    protected function setProductStock(int $id, string $xml_id)
    {
        // Получаем текущие значения свойства
        $stockValues = [];
        $arResult = \CIBlockElement::GetList(
            [],
            [
                'ID' => $id,
                'IBLOCK_ID' => $this->getCatalogIblockID()
            ],
            false,
            false,
            [static::PROP_NAME]
        );
        while($rs = $arResult->Fetch()) {
            $stockValues[] = $rs['PROPERTY_STOCK_VALUE'];
        }

        // Присваиваем/дополняем значение свойства
        if (!in_array($xml_id, $stockValues)) {
            $stockValues[] = $xml_id;
            $this->setStockProperty($id, $stockValues);
        }
    }

    /**
     * @param int $id
     * @param array $stockValues
     * @return void
     */
    protected function setStockProperty(int $id, array $stockValues)
    {
        \CIBlockElement::SetPropertyValuesEx(
            $id,
            $this->getCatalogIblockID(),
            [
                'STOCK' => $stockValues
            ]
        );
    }

    /**
     * @param string $xml_id
     * @return int|null
     * @throws ArgumentException
     * @throws LoaderException
     * @throws SystemException
     */
    protected function stockExist(string $xml_id)
    {
        \Bitrix\Main\Loader::IncludeModule("highloadblock");

        $rs = HighloadBlockTable::getList(array(
            'filter' => array('=TABLE_NAME' => static::HL_BLOCK_TABLE_NAME),
        ))->fetch();
        $hlBlockId = $rs['ID'];

        $hlBlock = HighloadBlockTable::getById($hlBlockId)->fetch();
        $entity = HighloadBlockTable::compileEntity($hlBlock);
        $entity_data_class = $entity->getDataClass();

        return $entity_data_class::getList(array(
            "filter" => array('UF_XML_ID' => $xml_id)
        ))->getSelectedRowsCount();
    }

    /**
     * @param int $id
     * @return int|null
     */
    protected function productExist(int $id)
    {
        return (\CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $this->getCatalogIblockID(),
                'ID' => $id
            ]
        )->SelectedRowsCount());
    }

    /**
     * @return int
     */
    protected function getCatalogIblockID(): int
    {
        return Iblock::getInstance()->getByCode('catalog', 'catalog')['ID'];
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getFilePath(): string
    {
        return $this->getDataDir().static::FILE_NAME;
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getDataDir(): string
    {
        if (
            (!$conf = Configuration::getValue('import'))
            || !$dir = $conf['dir']['stock']
        ){
            throw new \Exception("Не задана конфигурация импорта");
        }
        if (!file_exists($_SERVER['DOCUMENT_ROOT'].$dir)){
            throw new \Exception("Путь ".$_SERVER['DOCUMENT_ROOT'].$dir." отсутствует на сервере");
        }

        return $_SERVER['DOCUMENT_ROOT'].$dir;
    }
}
