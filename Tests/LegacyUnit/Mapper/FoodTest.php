<?php

declare(strict_types=1);

use OliverKlee\PhpUnit\TestCase;

/**
 * Test case.
 *
 * @author Niels Pardon <mail@niels-pardon.de>
 */
class Tx_Seminars_Tests_Unit_Mapper_FoodTest extends TestCase
{
    /**
     * @var \Tx_Oelib_TestingFramework
     */
    private $testingFramework = null;

    /**
     * @var \Tx_Seminars_Mapper_Food
     */
    private $subject = null;

    protected function setUp()
    {
        $this->testingFramework = new \Tx_Oelib_TestingFramework('tx_seminars');

        $this->subject = new \Tx_Seminars_Mapper_Food();
    }

    protected function tearDown()
    {
        $this->testingFramework->cleanUp();
    }

    //////////////////////////
    // Tests concerning find
    //////////////////////////

    /**
     * @test
     */
    public function findWithUidReturnsFoodInstance()
    {
        self::assertInstanceOf(\Tx_Seminars_Model_Food::class, $this->subject->find(1));
    }

    /**
     * @test
     */
    public function findWithUidOfExistingRecordReturnsRecordAsModel()
    {
        $uid = $this->testingFramework->createRecord(
            'tx_seminars_foods',
            ['title' => 'Crunchy crisps']
        );

        /** @var \Tx_Seminars_Model_Food $model */
        $model = $this->subject->find($uid);
        self::assertEquals(
            'Crunchy crisps',
            $model->getTitle()
        );
    }
}
