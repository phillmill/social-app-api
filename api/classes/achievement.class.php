<?php
class Achievement {
	public $name;
	public $description;
	public $image;
	public $order;

	public static function getAll() {
		global $f3, $db;

		$sql = "SELECT name, description, image, `order` FROM achievement ORDER BY `order`";
		$query = $db->prepare($sql);
		$query->execute();
		return $query->fetchAll(PDO::FETCH_OBJ);
	}
}