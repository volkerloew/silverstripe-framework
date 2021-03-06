<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\Dev\SapphireTest;

class TransactionTest extends SapphireTest {

	protected $extraDataObjects = array(
		TransactionTest\TestObject::class
	);

	public function testCreateWithTransaction() {

		if(DB::get_conn()->supportsTransactions()==true){
			DB::get_conn()->transactionStart();
			$obj=new TransactionTest\TestObject();
			$obj->Title='First page';
			$obj->write();

			$obj=new TransactionTest\TestObject();
			$obj->Title='Second page';
			$obj->write();

			//Create a savepoint here:
			DB::get_conn()->transactionSavepoint('rollback');

			$obj=new TransactionTest\TestObject();
			$obj->Title='Third page';
			$obj->write();

			$obj=new TransactionTest\TestObject();
			$obj->Title='Fourth page';
			$obj->write();

			//Revert to a savepoint:
			DB::get_conn()->transactionRollback('rollback');

			DB::get_conn()->transactionEnd();

			$first=DataObject::get(TransactionTest\TestObject::class, "\"Title\"='First page'");
			$second=DataObject::get(TransactionTest\TestObject::class, "\"Title\"='Second page'");
			$third=DataObject::get(TransactionTest\TestObject::class, "\"Title\"='Third page'");
			$fourth=DataObject::get(TransactionTest\TestObject::class, "\"Title\"='Fourth page'");

			//These pages should be in the system
			$this->assertTrue(is_object($first) && $first->exists());
			$this->assertTrue(is_object($second) && $second->exists());

			//These pages should NOT exist, we reverted to a savepoint:
			$this->assertFalse(is_object($third) && $third->exists());
			$this->assertFalse(is_object($fourth) && $fourth->exists());
		} else {
			$this->markTestSkipped('Current database does not support transactions');
		}
	}

}

