<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Entity\AccessUrl;
use Chamilo\CoreBundle\Entity\AccessUrlRelCourse;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Chamilo\CoreBundle\Repository\Node\CourseRepository;
use Chamilo\CoreBundle\Repository\Node\UserRepository;
use Chamilo\CoreBundle\Repository\SessionRepository;
use Chamilo\CourseBundle\Entity\CTool;
use Chamilo\CourseBundle\Repository\CToolRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

final class Version20201212195011 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Migrate courses, c_tool ';
    }

    public function up(Schema $schema): void
    {
        $container = $this->getContainer();
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        /** @var Connection $connection */
        $connection = $em->getConnection();

        $courseRepo = $container->get(CourseRepository::class);
        $sessionRepo = $container->get(SessionRepository::class);
        $toolRepo = $container->get(CToolRepository::class);
        $urlRepo = $em->getRepository(AccessUrl::class);
        $userRepo = $container->get(UserRepository::class);

        $batchSize = self::BATCH_SIZE;
        $admin = $this->getAdmin();
        $adminId = $admin->getId();

        // Adding courses to the resource node tree.
        $urls = $urlRepo->findAll();

        /** @var AccessUrl $url */
        foreach ($urls as $url) {
            $counter = 1;
            /** @var AccessUrl $urlEntity */
            $urlEntity = $urlRepo->find($url->getId());
            $accessUrlRelCourses = $urlEntity->getCourses();
            /** @var AccessUrlRelCourse $accessUrlRelCourse */
            foreach ($accessUrlRelCourses as $accessUrlRelCourse) {
                $course = $accessUrlRelCourse->getCourse();
                $course = $courseRepo->find($course->getId());
                if ($course->hasResourceNode()) {
                    continue;
                }
                $urlEntity = $urlRepo->find($url->getId());
                $adminEntity = $userRepo->find($adminId);
                $courseRepo->addResourceNode($course, $adminEntity, $urlEntity);
                $em->persist($course);

                // Add groups.
                //$course = $course->getGroups();
                if (($counter % $batchSize) === 0) {
                    $em->flush();
                    $em->clear(); // Detaches all objects from Doctrine!
                }
                $counter++;
            }
        }

        $em->flush();
        $em->clear();

        // Migrating c_tool.
        $q = $em->createQuery('SELECT c FROM Chamilo\CoreBundle\Entity\Course c');
        /** @var Course $course */
        foreach ($q->toIterable() as $course) {
            $counter = 1;
            $courseId = $course->getId();
            $sql = "SELECT * FROM c_tool
                    WHERE c_id = {$courseId} ";
            $result = $connection->executeQuery($sql);
            $tools = $result->fetchAllAssociative();

            foreach ($tools as $toolData) {
                /** @var CTool $tool */
                $tool = $toolRepo->find($toolData['iid']);
                if ($tool->hasResourceNode()) {
                    continue;
                }

                $course = $courseRepo->find($courseId);
                $session = null;
                if (!empty($toolData['session_id'])) {
                    $session = $sessionRepo->find($toolData['session_id']);
                }

                $admin = $this->getAdmin();
                $tool->setParent($course);
                $toolRepo->addResourceNode($tool, $admin, $course);
                $newVisibility = 1 === (int) $toolData['visibility'] ? ResourceLink::VISIBILITY_PUBLISHED : ResourceLink::VISIBILITY_PENDING;
                $tool->addCourseLink($course, $session, null, $newVisibility);
                $em->persist($tool);
                if (($counter % $batchSize) === 0) {
                    $em->flush();
                    $em->clear(); // Detaches all objects from Doctrine!
                }
                $counter++;
            }
        }
        $em->flush();
        $em->clear();
    }
}
